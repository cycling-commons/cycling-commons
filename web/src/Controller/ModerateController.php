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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Curator moderation queue — review pending submissions and record decisions.
 *
 * Decisions are applied by ModerationService, the only write-path for
 * moderation (approve applies the change to the catalog + appends
 * change_history; reject/needs_info never mutate the item). The queue is
 * populated from SubmissionQueue, which reads real pending/needs-info rows
 * from the submission table.
 *
 * @api Instantiated by Symfony's router — `@api` tells Psalm this is a live
 *      entry point, not dead code.
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
    ) {
    }

    #[Route('/moderate', name: 'moderate')]
    public function index(Request $request): Response
    {
        // Fire-and-forget housekeeping (throttled internally, never throws) —
        // must never delay or break the desk render.
        $this->retention->sweepOpportunistically();

        return $this->renderQueue(
            $request->query->getString('country'),
            $request->query->getString('region'),
            $request->query->getString('type'),
            q: $request->query->getString('q'),
            page: $request->query->getInt('page', 1),
        );
    }

    /**
     * Settled submissions — their own page since 2026-08-03 (owner).
     *
     * It grew from a footnote under the queue into a searchable, paged record
     * with a foldable conversation per row; a desk that is about what is still
     * to do should not carry an unbounded list of what is already done.
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
        // ?by=<user id> — "show me what this person has submitted", the question
        // the curator-application desk links here to ask. 0 means absent.
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
            // Option lists describe the SETTLED set here, not the open queue —
            // a country with no open work can still have a record worth reading.
            'countries' => $this->queue->countries($scope, settled: true),
            'regions' => $this->queue->regions($scope, settled: true),
            'types' => SubmissionType::values(),
            'pager' => Pager::of($page, $total, $perPage),
            ...$this->deskBadges($user, $scope),
        ]);
    }

    /**
     * Photo takedown requests — their own desk since 2026-08-03 (owner).
     *
     * They rode along under the submissions queue, which put a legal clock and
     * an editorial backlog on one page and made the takedowns look like the
     * bottom of somebody's to-do list. They are neither scoped nor filtered
     * (docs/specs/photo-uploads.md §6b): a rights request is not editorial work
     * to be shared out by jurisdiction, so every curator sees every one.
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

        /* The ANSWERED requests, below the open ones (owner-reported
           2026-08-14: "we had one request that was rejected and now we do not
           know of it"). This desk empties itself by design, so without a
           history a decided request left no trace on the only page anyone
           looks at — while every other desk has one. The decisions were being
           written to the event log the whole time; nothing was lost, there was
           just nowhere to see it.

           Its own page parameter, so paging the history cannot scroll the open
           requests out from under a curator halfway through answering one. */
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
     * The moderator rulebook — moderators only, deliberately NOT the public
     * wiki (2026-08-03, owner).
     *
     * A world-readable page describing how moderators decide what to destroy
     * is a social-engineering aid: it tells anyone which words get a
     * submission trashed and which get it escalated. The wiki copy is going;
     * this is where the rules live.
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
            ...$this->deskBadges($user, $scope),
        ]);
    }

    /**
     * The counts every moderation page's tab strip needs. Extracted so a new
     * desk cannot ship with a dead badge simply by forgetting to pass them.
     *
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

    /**
     * Render the moderation desk for the given filters. Shared by index() and
     * the invalid-decision branch of decide() so the queue-rendering block is
     * not duplicated and the curator's active filters are preserved on an
     * invalid submit rather than reset to unfiltered.
     */
    private function renderQueue(
        string $country,
        string $region,
        string $type,
        int $status = Response::HTTP_OK,
        string $q = '',
        int $page = 1,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $scope = $this->scopeProvider->scopeFor($user);
        $page = max(1, $page);
        $perPage = $this->pageSize->resolve(SubmissionQueue::PER_PAGE);
        $matching = $this->queue->countFiltered($scope, $country ?: null, $region ?: null, $type ?: null, $q ?: null);
        $items = $this->queue->filtered($scope, $country ?: null, $region ?: null, $type ?: null, $q ?: null, $page, $perPage);

        // No per-item decision forms here anymore: submissions are approved
        // ONLY from the map drawer (so a curator always sees the item in place
        // first). The list routes to /map?pending=<id> to review + decide.
        return $this->render('moderate/index.html.twig', [
            'page_title' => 'meta.moderate_title',
            'page_description' => 'meta.moderate_description',
            'nav_active' => 'moderate',
            'items' => $items,
            'total' => $this->queue->total($scope),
            'filters' => ['country' => $country, 'region' => $region, 'type' => $type, 'q' => $q],
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
                // The curator's own slip, not a state conflict: the submission
                // is still decidable and still in the queue. Say which of the
                // two it was, so the drawer can put the cursor in the note
                // rather than showing a generic failure.
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

            /* Approve & confirm in one stroke (owner 2026-08-13: "I know these
               roads by hand"). The curator's own confirmation is recorded
               AFTER the approval materialized the item, and it verifies
               through the existing weighted-by-who-pressed-it rule
               (ItemConfirmationService::verifyIfCurator) — no new mechanic,
               the same record every rider makes, reached from the decision.
               Best-effort on purpose: a letter whose stances do not include
               `exists` (water) simply skips, and a failure here must never
               undo a decision that already stands. */
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
                    // On approval, the item as the map's own pools carry it, so
                    // the drawer can put the contribution on the map in place
                    // of the pending pin it just removed. Null for the letters
                    // whose payload is not a feature collection (A/B/K) and for
                    // every non-approve decision — the client simply skips it.
                    // Sent for every approve, not just new items: approving an
                    // EDIT has to refresh the item the curator is looking at,
                    // or the drawer keeps showing the value they just changed.
                    'item' => 'approve' === $data['decision'] && null !== $submission->getItemId()
                        ? $catalog->featureForItem($submission->getItemId())
                        : null,
                ]);
            }

            // Redirect-after-POST (moderation-and-contribution.md §5.1):
            // preserve the curator's active filters instead of resetting to
            // an unfiltered queue.
            return $this->redirectToRoute('moderate', array_filter([
                'country' => $request->query->getString('country'),
                'region' => $request->query->getString('region'),
                'type' => $request->query->getString('type'),
            ], static fn (string $v): bool => '' !== $v));
        }

        if ($wantsJson) {
            return $this->json(['error' => 'invalid_decision'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Invalid form — re-render the queue preserving the curator's active
        // filters (from the action query string), not reset to unfiltered.
        return $this->renderQueue(
            $request->query->getString('country'),
            $request->query->getString('region'),
            $request->query->getString('type'),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * Trash (moderation-and-contribution.md §6): an immediate, permanent hard
     * delete of a spam/abusive submission — any status is legal (unlike a
     * route proposal, there's no state guardrail here). Audited content-free
     * by ModerationService; never sends the rider a message.
     */
    #[Route('/moderate/trash', name: 'moderate_trash', methods: ['POST'])]
    public function trash(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('moderate-trash', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // The typed-DELETE gate is gone (owner 2026-08-12): opening the
        // panel and pressing Trash inside it are the two deliberate acts,
        // and a word a curator types fifty times is a reflex, not a check.
        // Everything else about Trash is unchanged — irreversible, logged,
        // content-free record, no message sent.

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

        // §13 redirect-after-POST: preserve the curator's active filters
        // instead of resetting to an unfiltered queue.
        return $this->redirectToRoute('moderate', array_filter([
            'country' => $request->query->getString('country'),
            'region' => $request->query->getString('region'),
            'type' => $request->query->getString('type'),
        ], static fn (string $v): bool => '' !== $v));
    }

    /**
     * A photo takedown request, granted or declined
     * (docs/specs/photo-uploads.md §6b).
     *
     * The two verbs the rest of this desk already uses, and the curator is
     * answering one question with them: is this a rights claim ("that photo is
     * of me") or a change of mind about contributing? The first must be
     * honoured; the second must not be, or every approved photo in the commons
     * is only on loan. Nothing but the rider's own words tells them apart,
     * which is the entire reason a human is in this loop.
     */
    /**
     * Escalate a submission's contents as suspected illegal content
     * (docs/specs/photo-uploads.md §6d) — the same verb as the photo one
     * below, for the case where the words are the material.
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
     * Escalate a photo as suspected illegal content
     * (docs/specs/photo-uploads.md §6d).
     *
     * The third verb, and the only one a curator should reach for here.
     * Reject leaves the material in the queue for the next curator to meet;
     * Trash destroys it along with the evidence that it existed, which is
     * exactly what must survive until it has been reported. This hides it from
     * everyone including the desk, freezes it against every deletion path, and
     * puts it in front of an admin at once.
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

        // Idempotent by omission: a request somebody else has already decided
        // is simply no longer here, and a curator who double-submits gets the
        // refreshed desk rather than an error about a race they did not cause.
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
     * The unticked-photo list, defensively parsed: anything that is not a list
     * of uuid strings is treated as an empty list, which means the submission's
     * own decision applies to every photo — the safe reading either way
     * (docs/specs/photo-uploads.md §5).
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
