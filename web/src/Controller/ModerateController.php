<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\CatalogProvider;
use App\Catalog\ConfirmationStance;
use App\Catalog\Entity\Item;
use App\Catalog\ItemType;
use App\Catalog\SubmissionType;
use App\Community\ItemConfirmationService;
use App\Entity\User;
use App\Form\ModerationDecisionType;
use App\Media\Entity\MediaUpload;
use App\Media\MediaEscalationService;
use App\Media\MediaTakedownService;
use App\Media\UrgentWithholdBreaker;
use App\Moderation\AlreadyDecidedException;
use App\Moderation\MissingQuestionException;
use App\Moderation\ModerationScope;
use App\Moderation\ModerationScopeProvider;
use App\Moderation\ModerationService;
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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
        private readonly PageSize $pageSize,
        #[Autowire('%env(default::CC_RULEBOOK_PDF_URL)%')]
        private readonly ?string $rulebookPdfUrl = null,
    ) {
    }

    #[Route('/moderate', name: 'moderate')]
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
    #[Route('/moderate/history', name: 'moderate_history')]
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
            'nav_active' => 'moderate_history',
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
            'rulebook_pdf' => $this->rulebookPdfUrl,
            ...$this->deskBadges($user, $scope),
        ]);
    }

    /**
     * @return array{mod_scope_names: list<string>, mod_submission_count: int, mod_route_count: int, mod_takedown_count: int}
     */
    private function deskBadges(User $user, ModerationScope $scope): array
    {
        return [
            'mod_scope_names' => $this->scopeProvider->describe($user, $scope),
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

                return $this->redirectToRoute('moderate');
            } catch (AlreadyDecidedException|\InvalidArgumentException|\LogicException) {
                if ($wantsJson) {
                    return $this->json(['error' => 'undecidable_submission'], Response::HTTP_CONFLICT);
                }

                return $this->redirectToRoute('moderate');
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
            return $this->redirectToRoute('moderate', array_filter([
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

        return $this->redirectToRoute('moderate', array_filter([
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

            return $this->redirectToRoute('moderate');
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
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('moderate');
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

            return $this->redirectToRoute('moderate');
        }

        /** @var User $curator */
        $curator = $this->getUser();
        $upload = $this->em->find(MediaUpload::class, Uuid::fromString((string) $request->request->get('media')));
        if (null === $upload) {
            throw $this->createNotFoundException();
        }

        try {
            $this->escalations->escalate($upload, $curator, (string) $request->request->get('reason', ''));
            $this->addFlash('success', 'moderate.escalate.done');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('moderate');
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

        return $this->redirectToRoute('moderate');
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
