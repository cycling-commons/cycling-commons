<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\CatalogProvider;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Form\ModerationDecisionType;
use App\Media\Entity\MediaUpload;
use App\Media\MediaEscalationService;
use App\Media\MediaTakedownService;
use App\Media\UrgentWithholdBreaker;
use App\Moderation\AlreadyDecidedException;
use App\Moderation\MissingQuestionException;
use App\Moderation\ModerationScopeProvider;
use App\Moderation\ModerationService;
use App\Moderation\OutOfScopeException;
use App\Moderation\RetentionService;
use App\Moderation\RouteQueue;
use App\Moderation\SubmissionQueue;
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
        );
    }

    /**
     * Render the moderation desk for the given filters. Shared by index() and
     * the invalid-decision branch of decide() so the queue-rendering block is
     * not duplicated and the curator's active filters are preserved on an
     * invalid submit rather than reset to unfiltered.
     */
    private function renderQueue(string $country, string $region, string $type, int $status = Response::HTTP_OK): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $scope = $this->scopeProvider->scopeFor($user);
        $items = $this->queue->filtered($scope, $country ?: null, $region ?: null, $type ?: null);

        // No per-item decision forms here anymore: submissions are approved
        // ONLY from the map drawer (so a curator always sees the item in place
        // first). The list routes to /map?pending=<id> to review + decide.
        return $this->render('moderate/index.html.twig', [
            'page_title' => 'meta.moderate_title',
            'page_description' => 'meta.moderate_description',
            'nav_active' => 'moderate',
            'items' => $items,
            'total' => $this->queue->total($scope),
            'filters' => ['country' => $country, 'region' => $region, 'type' => $type],
            'countries' => $this->queue->countries($scope),
            'regions' => $this->queue->regions($scope),
            'types' => SubmissionType::values(),
            'receipt' => null,
            'mod_scope_names' => $this->scopeProvider->describe($user, $scope),
            // Both tab badges show the open count in the moderator's jurisdiction.
            'mod_submission_count' => $this->queue->total($scope),
            'mod_route_count' => $this->routeQueue->total($scope) + $this->routeQueue->pendingSuggestionCount($scope),
            // Not scoped and not filtered (docs/specs/photo-uploads.md §6b):
            // a rider asking for their own photo to come down is a rights
            // request on a legal clock, not editorial work to be shared out by
            // jurisdiction.
            'takedowns' => $this->takedowns->pendingCards(),
            // When the auto-withhold budget is spent, urgent reports are
            // arriving faster than any genuine rate and are NOT taking photos
            // down on their own (docs/specs/photo-uploads.md §6c). The desk
            // says so, because from here on the removals are the curator's.
            'urgent_breaker_open' => $this->breaker->isOpen(),
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
            /** @var array{submission_id: string|int, decision: string, note: ?string, media_reject: ?string} $data */
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

        // Typed confirmation (moderator rulebook): Trash is irreversible, so
        // the moderator must literally type DELETE. The desk's input enforces
        // this natively (pattern+required); this is the server-side re-check.
        // A cancelled/incomplete Trash never reaches here — no record is
        // written for a Trash that wasn't confirmed.
        if ('DELETE' !== $request->request->get('confirm')) {
            $this->addFlash('danger', 'moderate.trash.confirm_required');

            return $this->redirectToRoute('moderate', array_filter([
                'country' => $request->query->getString('country'),
                'region' => $request->query->getString('region'),
                'type' => $request->query->getString('type'),
            ]));
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
