<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\Entity\Item;
use App\Catalog\ItemState;
use App\Catalog\ItemType;
use App\Catalog\ServiceKind;
use App\Entity\User;
use App\Form\AddClimbType;
use App\Form\ImproveType;
use App\Form\VoteType;
use App\Routing\LocalePrefix;
use App\Service\ContributionReceipt;
use App\Service\ContributionStubInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Handles authenticated contribution actions: adding a climb, voting, and
 * improving an existing item.
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

                return $this->renderAddClimb(form: $form);
            } catch (ValidationFailedException $e) {
                foreach ($e->getViolations() as $violation) {
                    $form->addError(new FormError((string) $violation->getMessage()));
                }

                return $this->renderAddClimb(form: $form);
            }

            return $this->renderAddClimb(receipt: $receipt);
        }

        return $this->renderAddClimb(form: $form);
    }

    private function renderAddClimb(?ContributionReceipt $receipt = null, ?FormInterface $form = null): Response
    {
        return $this->render('contribute/add_climb.html.twig', [
            'page_title' => 'meta.add_climb_title',
            'page_description' => 'meta.add_climb_description',
            'nav_active' => 'add_climb',
            'receipt' => $receipt,
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

    /** The mode=add arm of /improve: submit → 'add' intake, or re-render. */
    private function addPlace(Request $request, ItemType $type): Response
    {
        $form = $this->createForm(ImproveType::class, null, [
            'catalog_type' => $type,
            'current' => [],
            'service_kind' => null,
            'add_mode' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            /** @var User $user */
            $user = $this->getUser();

            try {
                $receipt = $this->contributionStub->submit('add', ['type' => $type->value] + $data, $user);

                return $this->renderAddPlace($type, receipt: $receipt);
            } catch (TooManyRequestsHttpException) {
                $this->addFlash('error', 'contribute.error.rate_limited');
            } catch (ValidationFailedException $e) {
                foreach ($e->getViolations() as $violation) {
                    $form->addError(new FormError((string) $violation->getMessage()));
                }
            }
        }

        return $this->renderAddPlace($type, form: $form);
    }

    private function renderAddPlace(ItemType $type, ?ContributionReceipt $receipt = null, ?FormInterface $form = null): Response
    {
        return $this->render('contribute/improve.html.twig', [
            'page_title' => 'meta.improve_title',
            'page_description' => 'meta.improve_description',
            'nav_active' => 'improve',
            'item_type' => $type,
            'unbound' => false,
            'add_mode' => true,
            'receipt' => $receipt,
            'form' => $form,
        ]);
    }

    #[Route('/improve', name: 'improve')]
    #[IsGranted('ROLE_USER')]
    public function improve(Request $request, EntityManagerInterface $em): Response
    {
        // The edit flow is bound to a real item: the map edit-bridge always
        // sends `?item=<dbId>` (docs/specs/moderation-and-contribution.md
        // §1.4). No valid item id means no fake default editor, just an
        // explainer pointing to the map.
        //
        // Deliberately not `$request->query->getInt('item')`: InputBag::filter()
        // throws BadRequestHttpException on a non-numeric value (e.g. a stale
        // slug-based link) instead of coercing it. A non-numeric `item` is
        // exactly the "no valid item" case, not a 400.
        $item = null;
        $itemParam = (string) $request->query->get('item', '');
        // The edit-bridge also tells us which catalog type it opened. `type` is
        // the letter A–K (or the canonical slug) the map layer carried; an empty
        // param means a legacy/bare link with no declared type.
        $typeParam = (string) $request->query->get('type', '');
        $requestedType = '' === $typeParam ? null : ItemType::fromParam($typeParam);

        // K (Recommended routes) live in `recommended_route`, a separate table
        // and id sequence from `item`. The map renders the K "Edit this ride"
        // link as `/improve?item=<recommended_route.id>&type=K`, so resolving
        // that id against the item table would bind the edit to an unrelated
        // item that merely shares the numeric id (cross-sequence collision).
        // Route editing has no item binding yet, so K always falls through to
        // the unbound explainer.
        //
        // ctype_digit('') is false, so this also rejects a missing/blank param.
        if (ItemType::QualityRides !== $requestedType && ctype_digit($itemParam)) {
            // Only bind items in a publicly-served state: /map serves only
            // unverified/verified (docs/specs/catalog-data-model.md §4).
            // A 'submitted' item is another rider's un-moderated contribution;
            // 'rejected'/'retired' are withdrawn. Binding any of them would
            // prefill the form with data that no public read path exposes.
            // Everything else falls through to the unbound explainer.
            $item = $em->getRepository(Item::class)->findOneBy([
                'id' => (int) $itemParam,
                'state' => [ItemState::Unverified, ItemState::Verified],
            ]);
            // Guard the shared client-side id contract for A–J: the resolved row
            // must be the type the client opened. A mismatch means the id
            // collided across sequences (or the link was hand-crafted). Treat
            // it as unbound rather than editing the wrong item.
            if (null !== $item && null !== $requestedType && $item->getLetter() !== $requestedType->letter()) {
                $item = null;
            }
        }

        // "Add a new place" (moderation-and-contribution.md §1.1: mode=add) —
        // the same wizard, deliberately unbound: empty form, required name,
        // NewItem submission. Climbs keep the dedicated /add-climb flow and
        // K routes keep /propose-route, so both fall through to the explainer.
        if (null === $item && 'add' === (string) $request->query->get('mode', '')
            && null !== $requestedType
            && !\in_array($requestedType, [ItemType::Climbs, ItemType::QualityRides], true)) {
            return $this->addPlace($request, $requestedType);
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
        // D (BikeServices) kind-aware form: opening hours only makes sense
        // for a staffed shop, so the registry needs the concrete item's kind
        // to drop the field for a station/pump. Other types stay null.
        // See docs/specs/edit-items/D-bike-services.md.
        $serviceKind = ItemType::BikeServices === $type
            ? ServiceKind::tryFrom((string) ($item->getAttributes()['serviceKind'] ?? ''))
            : null;

        $form = $this->createForm(ImproveType::class, null, [
            'catalog_type' => $type,
            'current' => $current,
            'service_kind' => $serviceKind,
        ]);
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
            // Lets step 3 ("Photos & video") show the item's existing photo(s)
            // above the add-media controls, so a rider editing an item sees
            // what's already there instead of an empty upload prompt.
            'current' => $current,
        ]);
    }
}
