<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Form\ModerationDecisionType;
use App\Moderation\AlreadyDecidedException;
use App\Moderation\ModerationService;
use App\Moderation\RetentionService;
use App\Moderation\SubmissionQueue;
use App\Routing\LocalePrefix;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
     * invalid submit rather than reset to unfiltered (review #48).
     */
    private function renderQueue(string $country, string $region, string $type, int $status = Response::HTTP_OK): Response
    {
        $items = $this->queue->filtered($country ?: null, $region ?: null, $type ?: null);

        // The form action carries the live filters (§13) so a decision made from
        // a filtered view redirects back to that same filtered view.
        $formAction = $this->generateUrl('moderate_decide', array_filter([
            'country' => $country,
            'region' => $region,
            'type' => $type,
        ], static fn (string $v): bool => '' !== $v));

        $forms = [];
        foreach ($items as $item) {
            $form = $this->createForm(ModerationDecisionType::class, null, [
                'action' => $formAction,
                'method' => 'POST',
            ]);
            $forms[$item['id']] = $form->createView();
        }

        return $this->render('moderate/index.html.twig', [
            'page_title' => 'meta.moderate_title',
            'page_description' => 'meta.moderate_description',
            'nav_active' => 'moderate',
            'items' => $items,
            'forms' => $forms,
            'total' => $this->queue->total(),
            'filters' => ['country' => $country, 'region' => $region, 'type' => $type],
            'countries' => $this->queue->countries(),
            'regions' => $this->queue->regions(),
            'types' => SubmissionType::values(),
            'receipt' => null,
        ], Response::HTTP_OK === $status ? null : new Response('', $status));
    }

    #[Route('/moderate/decide', name: 'moderate_decide', methods: ['POST'])]
    public function decide(Request $request): Response
    {
        $form = $this->createForm(ModerationDecisionType::class);
        $form->handleRequest($request);

        $wantsJson = $request->isXmlHttpRequest()
            || \in_array('application/json', $request->getAcceptableContentTypes(), true);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{submission_id: string|int, decision: string, note: ?string} $data */
            $data = $form->getData();
            /** @var User $user */
            $user = $this->getUser();

            try {
                $submission = $this->moderation->decide((int) $data['submission_id'], (string) $data['decision'], $user, $data['note'] ?? null);
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
                ]);
            }

            // §13 redirect-after-POST: preserve the curator's active filters
            // instead of resetting to an unfiltered queue.
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
     * Trash (moderation-feedback spec M9): an immediate, permanent hard
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
}
