<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\CatalogProvider;
use App\Catalog\ConfirmationStance;
use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\Import\OsmCandidates;
use App\Catalog\Import\OsmLinker;
use App\Catalog\ItemType;
use App\Catalog\SubmissionType;
use App\Community\ItemConfirmationService;
use App\Entity\User;
use App\Form\ModerationDecisionType;
use App\Media\Entity\MediaUpload;
use App\Media\MediaEscalationService;
use App\Media\MediaTakedownService;
use App\Media\PhotoLocationConfirmation;
use App\Media\UrgentWithholdBreaker;
use App\Moderation\AlreadyDecidedException;
use App\Moderation\MissingQuestionException;
use App\Moderation\ModerationScope;
use App\Moderation\ModerationScopeProvider;
use App\Moderation\ModerationService;
use App\Moderation\OsmUnansweredException;
use App\Moderation\OutOfScopeException;
use App\Moderation\RetentionService;
use App\Moderation\RouteQueue;
use App\Moderation\SubmissionQueue;
use App\Pagination\Pager;
use App\Pagination\PageSize;
use App\Routing\LocalePrefix;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Curator moderation queue.
 *
 * @see docs/specs/moderation-and-contribution.md §5
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_CURATOR')]
final class ModerateController extends AbstractController
{
    public function __construct(
        private readonly ModerationService $moderation,
        private readonly SubmissionQueue $queue,
        private readonly RetentionService $retention,
        private readonly ModerationScopeProvider $scopeProvider,
        private readonly RouteQueue $routeQueue,
        private readonly MediaTakedownService $takedowns,
        private readonly MediaEscalationService $escalations,
        private readonly UrgentWithholdBreaker $breaker,
        private readonly EntityManagerInterface $em,
        private readonly ItemConfirmationService $confirmations,
        private readonly OsmLinker $linker,
        private readonly OsmCandidates $osmCandidates,
        private readonly PageSize $pageSize,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir = '',
        #[Autowire('%env(default::CC_RULEBOOK_PDF_PATH)%')]
        private readonly ?string $rulebookPdfPath = null,
    ) {
    }

    #[Route('/moderate/submissions', name: 'moderate_submissions')]
    public function index(Request $request): Response
    {
        $this->retention->sweepOpportunistically();

        return $this->renderQueue(
            $request->query->getString('country'),
            $request->query->getString('region'),
            $request->query->getString('type'),
            q: $request->query->getString('q'),
            page: $request->query->getInt('page', 1),
            byUser: $request->query->getInt('by') ?: null,
        );
    }

    /**
     * Settled submissions — searchable history, not the open queue.
     *
     * @see docs/specs/moderation-and-contribution.md §5.2
     */
    #[Route('/moderate/submissions/history', name: 'moderate_history')]
    public function history(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $scope = $this->scopeProvider->scopeFor($user);
        $mine = 'mine' === $request->query->getString('handled');
        $status = $request->query->getString('hstatus');
        $q = $request->query->getString('q');
        $country = $request->query->getString('country');
        $region = $request->query->getString('region');
        $type = $request->query->getString('type');
        $page = max(1, $request->query->getInt('page', 1));
        $byUser = $request->query->getInt('by') ?: null;
        $me = $mine ? $user->getId() : null;
        $perPage = $this->pageSize->resolve(SubmissionQueue::PER_PAGE);
        $total = $this->queue->countHistory($scope, $me, $status ?: null, $q ?: null, $country ?: null, $region ?: null, $type ?: null, $byUser);

        return $this->render('moderate/history.html.twig', [
            'page_title' => 'meta.moderate_history_title',
            'page_description' => 'meta.moderate_history_description',
            'nav_active' => 'moderate',
            'history' => $this->queue->history($scope, $me, $status ?: null, $q ?: null, $page, $perPage, $country ?: null, $region ?: null, $type ?: null, $byUser),
            'history_filters' => ['mine' => $mine, 'status' => $status, 'q' => $q, 'country' => $country, 'region' => $region, 'type' => $type, 'by' => $byUser],
            'countries' => $this->queue->countries($scope, settled: true),
            'regions' => $this->queue->regions($scope, settled: true),
            'types' => SubmissionType::values(),
            'pager' => Pager::of($page, $total, $perPage),
            ...$this->deskBadges($user, $scope),
        ]);
    }

    /**
     * Photo takedown desk — unscoped: every curator sees every request.
     *
     * @see docs/specs/photo-uploads.md §6b
     */
    #[Route('/moderate/takedowns', name: 'moderate_takedowns')]
    public function takedowns(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $scope = $this->scopeProvider->scopeFor($user);

        $pager = Pager::of(
            $request->query->getInt('page', 1),
            $this->takedowns->pendingCount(),
            $this->pageSize->resolve(MediaTakedownService::PER_PAGE),
        );

        $historyPager = Pager::of(
            $request->query->getInt('hpage', 1),
            $this->takedowns->decidedCount(),
            $this->pageSize->resolve(MediaTakedownService::PER_PAGE),
        );

        return $this->render('moderate/takedowns.html.twig', [
            'page_title' => 'meta.moderate_takedowns_title',
            'page_description' => 'meta.moderate_takedowns_description',
            'nav_active' => 'moderate_takedowns',
            'takedowns' => $this->takedowns->pendingCards($pager['page'], $pager['perPage']),
            'urgent_breaker_open' => $this->breaker->isOpen(),
            'pager' => $pager,
            'decided' => $this->takedowns->decidedCards($historyPager['page'], $historyPager['perPage']),
            'history_pager' => $historyPager,
            ...$this->deskBadges($user, $scope),
        ]);
    }

    /**
     * Moderator rulebook — curators only, not the public wiki.
     */
    #[Route('/moderate/rulebook', name: 'moderate_rulebook')]
    public function rulebook(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $scope = $this->scopeProvider->scopeFor($user);

        return $this->render('moderate/rulebook.html.twig', [
            'page_title' => 'meta.moderate_rulebook_title',
            'page_description' => 'meta.moderate_rulebook_description',
            'nav_active' => 'moderate_rulebook',
            'rulebook_pdf' => null !== $this->rulebookPdfFile(),
            ...$this->deskBadges($user, $scope),
        ]);
    }

    /**
     * The rulebook as a PDF, streamed to curators only (owner 2026-08-26).
     *
     * The file lives on the server at CC_RULEBOOK_PDF_PATH, outside public/ and
     * outside the repository: a file under public/ is readable by anyone who
     * has the URL, and the rulebook is a script for talking a curator into a
     * removal. This action is behind the class-level ROLE_CURATOR gate, marks
     * the response private and uncacheable, and tells crawlers to stay away.
     * 404 when nothing is configured or the file is missing; the page above
     * shows no link in that case, so nobody is sent here to find out.
     */
    #[Route('/moderate/rulebook.pdf', name: 'moderate_rulebook_pdf', methods: ['GET'])]
    public function rulebookPdf(): BinaryFileResponse
    {
        $file = $this->rulebookPdfFile();
        if (null === $file) {
            throw $this->createNotFoundException('No rulebook PDF is configured.');
        }

        $response = new BinaryFileResponse($file);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'moderator-rulebook.pdf');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * Absolute path of the configured rulebook PDF, or null when unset or
     * absent. A relative CC_RULEBOOK_PDF_PATH is taken from the project root,
     * so the committed default (`var/private/...`) works on every checkout
     * without naming a machine.
     */
    private function rulebookPdfFile(): ?string
    {
        $path = trim((string) $this->rulebookPdfPath);
        if ('' === $path) {
            return null;
        }
        if (!str_starts_with($path, '/')) {
            $path = rtrim($this->projectDir, '/').'/'.$path;
        }

        return is_file($path) && is_readable($path) ? $path : null;
    }

    /**
     * @return array{mod_submission_count: int, mod_route_count: int, mod_takedown_count: int}
     */
    private function deskBadges(User $user, ModerationScope $scope): array
    {
        return [
            'mod_submission_count' => $this->queue->total($scope),
            'mod_route_count' => $this->routeQueue->total($scope) + $this->routeQueue->pendingSuggestionCount($scope),
            'mod_takedown_count' => $this->takedowns->pendingCount(),
        ];
    }

    private function renderQueue(
        string $country,
        string $region,
        string $type,
        int $status = Response::HTTP_OK,
        string $q = '',
        int $page = 1,
        ?int $byUser = null,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $scope = $this->scopeProvider->scopeFor($user);
        $page = max(1, $page);
        $perPage = $this->pageSize->resolve(SubmissionQueue::PER_PAGE);
        $matching = $this->queue->countFiltered($scope, $country ?: null, $region ?: null, $type ?: null, $q ?: null, $byUser);
        $items = $this->queue->filtered($scope, $country ?: null, $region ?: null, $type ?: null, $q ?: null, $page, $perPage, $byUser);

        $items = $this->withOsmQuestion($items);

        // docs/specs/moderation-and-contribution.md §5.4 — decide from the map drawer, not this list.
        return $this->render('moderate/index.html.twig', [
            'page_title' => 'meta.moderate_title',
            'page_description' => 'meta.moderate_description',
            'nav_active' => 'moderate',
            'items' => $items,
            'total' => $this->queue->total($scope),
            'filters' => ['country' => $country, 'region' => $region, 'type' => $type, 'q' => $q, 'by' => $byUser],
            'pager' => Pager::of($page, $matching, $perPage),
            'matching' => $matching,
            'countries' => $this->queue->countries($scope),
            'regions' => $this->queue->regions($scope),
            'types' => SubmissionType::values(),
            'receipt' => null,
            ...$this->deskBadges($user, $scope),
        ], Response::HTTP_OK === $status ? null : new Response('', $status));
    }

    /**
     * Attach the OSM question to every pending new-place card that still needs
     * an answer (catalog-data-model.md §5b). One query for the items, one
     * spatial lookup per unanswered card; the queue page is small.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function withOsmQuestion(array $items): array
    {
        $ids = [];
        foreach ($items as $card) {
            if (SubmissionType::NewItem->value === ($card['type'] ?? null)
                && \in_array($card['status'] ?? '', ['pending', 'needs_info'], true)
                && null !== ($card['itemId'] ?? null)) {
                $ids[] = (int) $card['itemId'];
            }
        }
        if ([] === $ids) {
            return $items;
        }

        // Every new place gets an OSM chip, in one of three states: open (no
        // answer yet: the row offers the candidates and "Not in OSM"), linked,
        // or none. Until 2026-08-25 only the open state was shown, so a row
        // that had been answered looked exactly like one nobody had asked about.
        /** @var list<array{id: string|int, osm_ref: string|null, osm_checked_at: string|null}> $rows */
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, osm_ref, osm_checked_at FROM item WHERE id IN (:ids)',
            ['ids' => $ids],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
        // Stored once per row, never asked of the coverage table per list view
        // (catalog-data-model.md §5b, owner 2026-08-25).
        $candidates = $this->osmCandidates->forItems($ids);
        $osm = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (null === $row['osm_checked_at']) {
                $osm[$id] = ['state' => 'open', 'ref' => null, 'candidates' => $candidates[$id] ?? []];
            } elseif (\is_string($row['osm_ref']) && '' !== $row['osm_ref']) {
                $osm[$id] = ['state' => 'linked', 'ref' => $row['osm_ref'], 'candidates' => []];
            } else {
                $osm[$id] = ['state' => 'none', 'ref' => null, 'candidates' => []];
            }
        }

        foreach ($items as &$card) {
            $itemId = $card['itemId'] ?? null;
            if (null !== $itemId && \array_key_exists((int) $itemId, $osm)) {
                $card['osm'] = $osm[(int) $itemId];
            }
        }

        return $items;
    }

    /**
     * The curator answers the OSM question: a ref links it, an empty ref is
     * "this place is not in OSM". Both are one click, neither is a default
     * (catalog-data-model.md §5b).
     */
    #[Route('/moderate/osm-answer', name: 'moderate_osm_answer', methods: ['POST'])]
    public function osmAnswer(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('moderate-osm-answer', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $curator */
        $curator = $this->getUser();
        $submissionId = $request->request->getInt('submission_id');
        $ref = trim((string) $request->request->get('ref'));

        // A hand-made ref must still be an OSM object the poi endpoint would
        // serve; anything else could not be the identity of anything.
        if ('' !== $ref && 1 !== preg_match('~^(node|way)/\d+$~', $ref)) {
            $this->addFlash('danger', 'moderate.osm.bad_ref');

            return $this->redirectToRoute('moderate_submissions');
        }

        $submission = $this->em->find(Submission::class, $submissionId);
        if (null === $submission || null === $submission->getItemId()) {
            $this->addFlash('danger', 'moderate.osm.bad_ref');

            return $this->redirectToRoute('moderate_submissions');
        }
        // Same boundary decide() enforces, before anything is written.
        if (!$this->scopeProvider->allowsRegion($this->scopeProvider->scopeFor($curator), $submission->getRegionId())) {
            throw $this->createAccessDeniedException('Out of moderation scope.');
        }

        $item = $this->em->find(Item::class, $submission->getItemId());
        if (null === $item) {
            $this->addFlash('danger', 'moderate.osm.bad_ref');

            return $this->redirectToRoute('moderate_submissions');
        }

        // Identity is exclusive: linking to an object another served row
        // already claims would mint the duplicate the desk exists to remove.
        if ('' !== $ref && $this->linker->refIsTaken($ref, $item->getLetter(), (int) $item->getId())) {
            $this->addFlash('danger', 'moderate.osm.ref_taken');

            return $this->redirectToRoute('moderate_submissions');
        }

        $item->answerOsm('' === $ref ? null : $ref);
        $this->em->flush();
        $this->addFlash('success', '' === $ref ? 'moderate.osm.answered_none' : 'moderate.osm.answered_linked');

        return $this->redirectToRoute('moderate_submissions');
    }

    #[Route('/moderate/decide', name: 'moderate_decide', methods: ['POST'])]
    public function decide(Request $request, CatalogProvider $catalog): Response
    {
        $form = $this->createForm(ModerationDecisionType::class);
        $form->handleRequest($request);

        $wantsJson = $request->isXmlHttpRequest()
            || \in_array('application/json', $request->getAcceptableContentTypes(), true);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{submission_id: string|int, decision: string, note: ?string, media_reject: ?string, and_confirm: ?string} $data */
            $data = $form->getData();
            /** @var User $user */
            $user = $this->getUser();

            try {
                $submission = $this->moderation->decide(
                    (int) $data['submission_id'],
                    (string) $data['decision'],
                    $user,
                    $data['note'] ?? null,
                    self::parseMediaReject($data['media_reject'] ?? null),
                );
            } catch (OutOfScopeException) {
                throw $this->createAccessDeniedException('Out of moderation scope.');
            } catch (MissingQuestionException) {
                if ($wantsJson) {
                    return $this->json(['error' => 'needs_info_note_required'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $this->addFlash('error', 'moderate.error.needs_info_note_required');

                return $this->redirectToRoute('moderate_submissions');
            } catch (OsmUnansweredException) {
                // catalog-data-model.md §5b - the card shows the question; this
                // is the curator clicking approve before answering it.
                if ($wantsJson) {
                    return $this->json(['error' => 'osm_unanswered'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $this->addFlash('error', 'moderate.error.osm_unanswered');

                return $this->redirectToRoute('moderate_submissions');
            } catch (AlreadyDecidedException|\InvalidArgumentException|\LogicException) {
                if ($wantsJson) {
                    return $this->json(['error' => 'undecidable_submission'], Response::HTTP_CONFLICT);
                }

                return $this->redirectToRoute('moderate_submissions');
            }

            // docs/specs/moderation-and-contribution.md (A curator's confirmation verifies the item) — best-effort after approve.
            if ('approve' === $data['decision'] && '1' === ($data['and_confirm'] ?? null) && null !== $submission->getItemId()) {
                $item = $this->em->find(Item::class, $submission->getItemId());
                if (null !== $item && \in_array(ConfirmationStance::Exists, ItemType::fromParam($item->getLetter())->confirmationStances(), true)) {
                    $this->confirmations->record($item, $user, ConfirmationStance::Exists);
                    $this->em->flush();
                }
            }

            if ($wantsJson) {
                return $this->json([
                    'reference' => 'SUB-'.(string) $submission->getId(),
                    'kind' => 'moderation_decision',
                    'persisted' => true,
                    'decision' => $data['decision'],
                    'submission_id' => $submission->getId(),
                    'item' => 'approve' === $data['decision'] && null !== $submission->getItemId()
                        ? $catalog->featureForItem($submission->getItemId())
                        : null,
                ]);
            }

            // docs/specs/moderation-and-contribution.md §5.1 — keep active filters.
            return $this->redirectToRoute('moderate_submissions', array_filter([
                'country' => $request->query->getString('country'),
                'region' => $request->query->getString('region'),
                'type' => $request->query->getString('type'),
            ], static fn (string $v): bool => '' !== $v));
        }

        if ($wantsJson) {
            return $this->json(['error' => 'invalid_decision'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->renderQueue(
            $request->query->getString('country'),
            $request->query->getString('region'),
            $request->query->getString('type'),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * Permanent hard delete of a spam/abusive submission. Audited content-free; no rider message.
     *
     * @see docs/specs/moderation-and-contribution.md §5
     */
    #[Route('/moderate/trash', name: 'moderate_trash', methods: ['POST'])]
    public function trash(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('moderate-trash', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $kind = (string) $request->request->get('kind');
        $id = (int) $request->request->get('id');
        /** @var User $curator */
        $curator = $this->getUser();

        try {
            if ('submission' !== $kind) {
                throw new \InvalidArgumentException('Unknown trash kind.');
            }
            $this->moderation->trashSubmission($id, $curator);
            $this->addFlash('success', 'moderate.trash.done');
        } catch (OutOfScopeException) {
            throw $this->createAccessDeniedException('Out of moderation scope.');
        } catch (\InvalidArgumentException) {
            $this->addFlash('danger', 'moderate.trash.error');
        }

        return $this->redirectToRoute('moderate_submissions', array_filter([
            'country' => $request->query->getString('country'),
            'region' => $request->query->getString('region'),
            'type' => $request->query->getString('type'),
        ], static fn (string $v): bool => '' !== $v));
    }

    /**
     * Escalate a submission as suspected illegal content.
     *
     * @see docs/specs/photo-uploads.md §6d
     */
    #[Route('/moderate/escalate-submission', name: 'moderate_escalate_submission', methods: ['POST'])]
    public function escalateSubmission(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('moderate_escalate', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'flash.invalid_token');

            return $this->redirectToRoute('moderate_submissions');
        }

        /** @var User $curator */
        $curator = $this->getUser();
        try {
            $this->moderation->escalateSubmission(
                (int) $request->request->get('submission'),
                $curator,
                (string) $request->request->get('reason', ''),
            );
            $this->addFlash('success', 'moderate.escalate.done');
        } catch (OutOfScopeException) {
            throw $this->createAccessDeniedException('Out of moderation scope.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('moderate_submissions');
    }

    /**
     * Escalate a photo as suspected illegal content.
     *
     * @see docs/specs/photo-uploads.md §6d
     */
    #[Route('/moderate/escalate', name: 'moderate_escalate', methods: ['POST'])]
    public function escalate(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('moderate_escalate', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'flash.invalid_token');

            return $this->redirectToRoute('moderate_submissions');
        }

        /** @var User $curator */
        $curator = $this->getUser();
        $upload = $this->em->find(MediaUpload::class, Uuid::fromString((string) $request->request->get('media')));
        if (null === $upload) {
            throw $this->createNotFoundException();
        }
        // Same write-guard as escalateSubmission above (security scan
        // 2026-08-25). The region comes from the photo's OWNING submission,
        // because that is where a photo gets its place: an unclaimed upload
        // has no region yet, and a null region is in-scope for everyone, which
        // is the same rule allowsRegion() applies everywhere else.
        if (!$this->scopeProvider->allowsRegion(
            $this->scopeProvider->scopeFor($curator),
            $this->regionOfUpload($upload),
        )) {
            throw $this->createAccessDeniedException('Out of moderation scope.');
        }

        try {
            $this->escalations->escalate($upload, $curator, (string) $request->request->get('reason', ''));
            $this->addFlash('success', 'moderate.escalate.done');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('moderate_submissions');
    }

    /**
     * Confirm a rider photo on a scenic view was taken at the pin.
     *
     * The map drawer's "Taken here" button posts here. 404 unless the uuid names
     * an approved rider photo on a scenic view (letter P) that is not under
     * legal hold. The write-guard is the item's region, because the confirmation
     * is about that item's pin.
     *
     * @see docs/specs/photo-uploads.md §5g
     * @see docs/specs/scenic-views.md §8
     */
    #[Route('/moderate/photo/{uuid}/taken-here', name: 'moderate_photo_taken_here', methods: ['POST'])]
    public function photoTakenHere(string $uuid, Request $request, PhotoLocationConfirmation $confirmation): JsonResponse
    {
        if (!$this->isCsrfTokenValid('photo-taken-here', (string) $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_token'], Response::HTTP_FORBIDDEN);
        }

        $found = $confirmation->confirmable($uuid);
        if (null === $found) {
            return new JsonResponse(['ok' => false, 'error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        /** @var User $curator */
        $curator = $this->getUser();
        if (!$this->scopeProvider->allowsRegion($this->scopeProvider->scopeFor($curator), $found['item']->getRegionId())) {
            return new JsonResponse(['ok' => false, 'error' => 'out_of_scope'], Response::HTTP_FORBIDDEN);
        }

        $confirmation->confirm($found['upload'], $curator);

        return new JsonResponse(['ok' => true]);
    }

    /**
     * Region a photo belongs to, via its owning submission. null when the
     * upload is not claimed by one yet.
     *
     * @see docs/specs/moderation-and-contribution.md §9.2
     */
    private function regionOfUpload(MediaUpload $upload): ?int
    {
        $submissionId = $upload->getSubmissionId();
        if (null === $submissionId) {
            return null;
        }

        return $this->em->find(Submission::class, $submissionId)?->getRegionId();
    }

    #[Route('/moderate/takedown', name: 'moderate_takedown', methods: ['POST'])]
    public function takedown(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('moderate_takedown', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $curator */
        $curator = $this->getUser();
        $raw = (string) $request->request->get('media', '');
        $upload = Uuid::isValid($raw) ? $this->em->find(MediaUpload::class, Uuid::fromString($raw)) : null;
        $note = trim((string) $request->request->get('note', '')) ?: null;

        if (null !== $upload && $upload->isTakedownPending()) {
            if ('grant' === $request->request->get('decision')) {
                $this->takedowns->grant($upload, $curator, $note);
                $this->addFlash('success', 'moderate.takedown.granted');
            } else {
                $this->takedowns->decline($upload, $curator, $note);
                $this->addFlash('success', 'moderate.takedown.declined');
            }
        }

        return $this->redirectToRoute('moderate_submissions');
    }

    /**
     * Unticked photos: garbage JSON means empty — the submission decision applies to every photo.
     *
     * @see docs/specs/photo-uploads.md §5
     *
     * @return list<string>
     */
    private static function parseMediaReject(?string $raw): array
    {
        if (null === $raw || '' === trim($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            $decoded,
            static fn (mixed $v): bool => \is_string($v) && Uuid::isValid($v),
        ));
    }
}
