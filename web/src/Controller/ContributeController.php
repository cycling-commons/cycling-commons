<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\ItemType;
use App\Entity\User;
use App\Form\AddClimbType;
use App\Form\ImproveType;
use App\Form\VoteType;
use App\Routing\LocalePrefix;
use App\Service\ContributionStubInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Handles authenticated contribution actions (vote, and future tasks).
 *
 * @api Instantiated by Symfony's router — `@api` tells Psalm this is a live
 *      entry point, not dead code.
 */
#[Route(LocalePrefix::PATHS)]
final class ContributeController extends AbstractController
{
    public function __construct(
        private readonly ContributionStubInterface $contributionStub,
    ) {
    }

    #[Route('/contribute', name: 'contribute')]
    public function index(): Response
    {
        return $this->render('contribute/index.html.twig', [
            'page_title' => 'meta.contribute_title',
            'page_description' => 'meta.contribute_description',
            'nav_active' => '',
        ]);
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

            try {
                $receipt = $this->contributionStub->submit('climb', $data, $user);
            } catch (TooManyRequestsHttpException) {
                $this->addFlash('error', 'contribute.error.rate_limited');

                return $this->render('contribute/add_climb.html.twig', [
                    'page_title' => 'meta.add_climb_title',
                    'page_description' => 'meta.add_climb_description',
                    'nav_active' => 'add_climb',
                    'receipt' => null,
                    'form' => $form,
                ]);
            } catch (ValidationFailedException $e) {
                foreach ($e->getViolations() as $violation) {
                    $form->addError(new FormError((string) $violation->getMessage()));
                }

                return $this->render('contribute/add_climb.html.twig', [
                    'page_title' => 'meta.add_climb_title',
                    'page_description' => 'meta.add_climb_description',
                    'nav_active' => 'add_climb',
                    'receipt' => null,
                    'form' => $form,
                ]);
            }

            return $this->render('contribute/add_climb.html.twig', [
                'page_title' => 'meta.add_climb_title',
                'page_description' => 'meta.add_climb_description',
                'nav_active' => 'add_climb',
                'receipt' => $receipt,
                'form' => null,
            ]);
        }

        return $this->render('contribute/add_climb.html.twig', [
            'page_title' => 'meta.add_climb_title',
            'page_description' => 'meta.add_climb_description',
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
                'page_title' => 'meta.vote_title',
                'page_description' => 'meta.vote_description',
                'nav_active' => 'vote',
                'receipt' => $receipt,
                'form' => null,
            ]);
        }

        return $this->render('contribute/vote.html.twig', [
            'page_title' => 'meta.vote_title',
            'page_description' => 'meta.vote_description',
            'nav_active' => 'vote',
            'receipt' => null,
            'form' => $form,
        ]);
    }

    #[Route('/improve', name: 'improve')]
    #[IsGranted('ROLE_USER')]
    public function improve(Request $request): Response
    {
        // ?type=<A–K slug or letter> picks the type-aware form; unknown/absent →
        // the default (D · bike services), mirroring the demo's fallback item.
        $type = ItemType::fromParam($request->query->getString('type'));

        $form = $this->createForm(ImproveType::class, null, ['catalog_type' => $type]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            /** @var User $user */
            $user = $this->getUser();

            // TODO(data-api): persist the catalog edit via the data API (later spec).
            $receipt = $this->contributionStub->submit('improve', ['type' => $type->value] + $data, $user);

            return $this->render('contribute/improve.html.twig', [
                'page_title' => 'meta.improve_title',
                'page_description' => 'meta.improve_description',
                'nav_active' => 'improve',
                'item_type' => $type,
                'receipt' => $receipt,
                'form' => null,
            ]);
        }

        return $this->render('contribute/improve.html.twig', [
            'page_title' => 'meta.improve_title',
            'page_description' => 'meta.improve_description',
            'nav_active' => 'improve',
            'item_type' => $type,
            'receipt' => null,
            'form' => $form,
        ]);
    }
}
