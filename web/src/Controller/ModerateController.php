<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Form\ModerationDecisionType;
use App\Moderation\AlreadyDecidedException;
use App\Moderation\ModerationService;
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
    ) {
    }

    #[Route('/moderate', name: 'moderate')]
    public function index(Request $request): Response
    {
        $country = $request->query->getString('country');
        $region = $request->query->getString('region');
        $type = $request->query->getString('type');

        $items = $this->queue->filtered($country ?: null, $region ?: null, $type ?: null);

        // Build one decision form per shown queue item. The action carries the
        // live filters (§13) so a decision made from a filtered view redirects
        // back to that same filtered view rather than resetting it.
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
        ]);
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
            } catch (AlreadyDecidedException|\InvalidArgumentException) {
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

        // Invalid form — re-render the queue (unfiltered).
        $items = $this->queue->filtered(null, null, null);

        $forms = [];
        foreach ($items as $item) {
            $newForm = $this->createForm(ModerationDecisionType::class, null, [
                'action' => $this->generateUrl('moderate_decide'),
                'method' => 'POST',
            ]);
            $forms[$item['id']] = $newForm->createView();
        }

        if ($wantsJson) {
            return $this->json(['error' => 'invalid_decision'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->render('moderate/index.html.twig', [
            'page_title' => 'meta.moderate_title',
            'page_description' => 'meta.moderate_description',
            'nav_active' => 'moderate',
            'items' => $items,
            'forms' => $forms,
            'total' => $this->queue->total(),
            'filters' => ['country' => '', 'region' => '', 'type' => ''],
            'countries' => $this->queue->countries(),
            'regions' => $this->queue->regions(),
            'types' => SubmissionType::values(),
            'receipt' => null,
        ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
