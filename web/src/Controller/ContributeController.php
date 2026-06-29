<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\AddClimbType;
use App\Form\VoteType;
use App\Service\ContributionStubInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Handles authenticated contribution actions (vote, and future tasks).
 *
 * @api Instantiated by Symfony's router — `@api` tells Psalm this is a live
 *      entry point, not dead code.
 */
final class ContributeController extends AbstractController
{
    public function __construct(
        private readonly ContributionStubInterface $contributionStub,
    ) {
    }

    #[Route('/add-climb', name: 'add_climb')]
    #[IsGranted('ROLE_USER')]
    public function addClimb(Request $request): Response
    {
        $form = $this->createForm(AddClimbType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            /** @var User $user */
            $user = $this->getUser();

            $receipt = $this->contributionStub->submit('climb', $data, $user);

            return $this->render('contribute/add_climb.html.twig', [
                'page_title' => 'Cycling Commons — Add a climb',
                'page_description' => 'Add a climb to the Commons — search the place, trace the route, and capture surface, traffic and gradient for every kind of rider.',
                'nav_active' => 'add_climb',
                'receipt' => $receipt,
                'form' => null,
            ]);
        }

        return $this->render('contribute/add_climb.html.twig', [
            'page_title' => 'Cycling Commons — Add a climb',
            'page_description' => 'Add a climb to the Commons — search the place, trace the route, and capture surface, traffic and gradient for every kind of rider.',
            'nav_active' => 'add_climb',
            'receipt' => null,
            'form' => $form,
        ]);
    }

    #[Route('/vote', name: 'vote')]
    #[IsGranted('ROLE_USER')]
    public function vote(Request $request): Response
    {
        $form = $this->createForm(VoteType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            /** @var User $user */
            $user = $this->getUser();

            $receipt = $this->contributionStub->submit('vote', $data, $user);

            return $this->render('contribute/vote.html.twig', [
                'page_title' => 'Cycling Commons — Vote',
                'page_description' => "Rank this region's best riding. The community decides the seasonal best-of, kept fresh by the riders who ride it.",
                'nav_active' => 'vote',
                'receipt' => $receipt,
                'form' => null,
            ]);
        }

        return $this->render('contribute/vote.html.twig', [
            'page_title' => 'Cycling Commons — Vote',
            'page_description' => "Rank this region's best riding. The community decides the seasonal best-of, kept fresh by the riders who ride it.",
            'nav_active' => 'vote',
            'receipt' => null,
            'form' => $form,
        ]);
    }
}
