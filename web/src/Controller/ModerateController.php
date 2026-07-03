<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ModerationDecisionType;
use App\Moderation\SampleQueue;
use App\Routing\LocalePrefix;
use App\Service\ContributionStubInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Curator moderation queue — review pending submissions and record decisions.
 *
 * Decisions are forwarded to the contribution stub (no real state change
 * until the data API is ready). The queue is populated from SampleQueue
 * while no domain entities exist.
 *
 * @api Instantiated by Symfony's router — `@api` tells Psalm this is a live
 *      entry point, not dead code.
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_CURATOR')]
final class ModerateController extends AbstractController
{
    private const TYPES = ['new', 'edit', 'hazard', 'photo'];

    public function __construct(
        private readonly ContributionStubInterface $contributionStub,
    ) {
    }

    #[Route('/moderate', name: 'moderate')]
    public function index(Request $request): Response
    {
        $country = $request->query->getString('country');
        $region = $request->query->getString('region');
        $type = $request->query->getString('type');

        $items = SampleQueue::filtered($country ?: null, $region ?: null, $type ?: null);

        // Build one decision form per shown queue item.
        $forms = [];
        foreach ($items as $item) {
            $form = $this->createForm(ModerationDecisionType::class, null, [
                'action' => $this->generateUrl('moderate'),
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
            'total' => \count(SampleQueue::items()),
            'filters' => ['country' => $country, 'region' => $region, 'type' => $type],
            'countries' => SampleQueue::countries(),
            'regions' => SampleQueue::regions(),
            'types' => self::TYPES,
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
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            /** @var User $user */
            $user = $this->getUser();

            $receipt = $this->contributionStub->submit('moderation_decision', $data, $user);

            if ($wantsJson) {
                return $this->json([
                    'reference' => $receipt->reference,
                    'kind' => $receipt->kind,
                    'persisted' => $receipt->persisted,
                    'decision' => $data['decision'],
                    'submission_id' => $data['submission_id'],
                ]);
            }

            $items = SampleQueue::items();

            // Build one decision form per queue item (for the rendered queue after decision).
            $forms = [];
            foreach ($items as $item) {
                $newForm = $this->createForm(ModerationDecisionType::class, null, [
                    'action' => $this->generateUrl('moderate'),
                    'method' => 'POST',
                ]);
                $forms[$item['id']] = $newForm->createView();
            }

            return $this->render('moderate/index.html.twig', [
                'page_title' => 'Cycling Commons — Moderate',
                'page_description' => 'Curator surface for the Commons — review submissions, resolve flags, and keep the open cycling atlas trustworthy.',
                'nav_active' => 'moderate',
                'items' => $items,
                'forms' => $forms,
                'total' => \count($items),
                'filters' => ['country' => '', 'region' => '', 'type' => ''],
                'countries' => SampleQueue::countries(),
                'regions' => SampleQueue::regions(),
                'types' => self::TYPES,
                'receipt' => $receipt,
            ]);
        }

        // Invalid form — re-render the queue.
        $items = SampleQueue::items();

        $forms = [];
        foreach ($items as $item) {
            $newForm = $this->createForm(ModerationDecisionType::class, null, [
                'action' => $this->generateUrl('moderate'),
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
            'total' => \count($items),
            'filters' => ['country' => '', 'region' => '', 'type' => ''],
            'countries' => SampleQueue::countries(),
            'regions' => SampleQueue::regions(),
            'types' => self::TYPES,
            'receipt' => null,
        ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
