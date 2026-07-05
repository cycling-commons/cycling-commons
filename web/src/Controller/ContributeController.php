<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\Entity\Item;
use App\Catalog\ItemType;
use App\Entity\User;
use App\Form\AddClimbType;
use App\Form\ImproveType;
use App\Form\VoteType;
use App\Routing\LocalePrefix;
use App\Service\ContributionStubInterface;
use Doctrine\ORM\EntityManagerInterface;
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
    public function improve(Request $request, EntityManagerInterface $em): Response
    {
        // The edit flow is bound to a real item — the map edit-bridge always
        // sends `?item=<dbId>` (spec §6/§8). No valid item id: no more fake
        // default editor, just an explainer pointing to the map.
        //
        // Deliberately not `$request->query->getInt('item')`: InputBag::filter()
        // throws BadRequestHttpException on a non-numeric value (e.g. a stale
        // slug-based link) instead of coercing it — a non-numeric `item` is
        // exactly the "no valid item" case, not a 400.
        $item = null;
        $itemParam = (string) $request->query->get('item', '');
        // ctype_digit('') is false, so this also rejects a missing/blank param.
        if (ctype_digit($itemParam)) {
            $item = $em->find(Item::class, (int) $itemParam);
        }

        if (null === $item) {
            return $this->render('contribute/improve.html.twig', [
                'page_title' => 'meta.improve_title',
                'page_description' => 'meta.improve_description',
                'nav_active' => 'improve',
                'item_type' => ItemType::default(),
                'unbound' => true,
                'receipt' => null,
                'form' => null,
            ]);
        }

        $type = ItemType::fromParam($item->getLetter());
        $current = ['name' => $item->getName()] + $item->getAttributes();

        $form = $this->createForm(ImproveType::class, null, ['catalog_type' => $type, 'current' => $current]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            /** @var User $user */
            $user = $this->getUser();

            try {
                $receipt = $this->contributionStub->submit('improve', ['type' => $type->value, '_item_id' => $item->getId()] + $data, $user);
            } catch (TooManyRequestsHttpException) {
                $this->addFlash('error', 'contribute.error.rate_limited');
                $receipt = null;
            } catch (ValidationFailedException $e) {
                foreach ($e->getViolations() as $violation) {
                    $form->addError(new FormError((string) $violation->getMessage()));
                }
                $receipt = null;
            }

            if (null !== $receipt) {
                return $this->render('contribute/improve.html.twig', [
                    'page_title' => 'meta.improve_title',
                    'page_description' => 'meta.improve_description',
                    'nav_active' => 'improve',
                    'item_type' => $type,
                    'unbound' => false,
                    'edit_name' => $item->getName(),
                    'receipt' => $receipt,
                    'form' => null,
                ]);
            }
        }

        return $this->render('contribute/improve.html.twig', [
            'page_title' => 'meta.improve_title',
            'page_description' => 'meta.improve_description',
            'nav_active' => 'improve',
            'item_type' => $type,
            'unbound' => false,
            'edit_name' => $item->getName(),
            'receipt' => null,
            'form' => $form,
            // C5: lets step 3 ("Photos & video") show the item's EXISTING photo(s)
            // above the add-media controls — a rider editing an item should see
            // what's already there, not just an empty upload prompt.
            'current' => $current,
        ]);
    }
}
