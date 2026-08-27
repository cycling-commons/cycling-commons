<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\CatalogFormRegistry;
use App\Catalog\Entity\Item;
use App\Catalog\ItemState;
use App\Catalog\ItemType;
use App\Catalog\LocationMode;
use App\Catalog\ServiceKind;
use App\Catalog\SurfaceVocabulary;
use App\Contribution\CatalogContributionService;
use App\Coverage\CoverageRepository;
use App\Entity\User;
use App\Form\ImproveType;
use App\Form\VoteType;
use App\Routing\LocalePrefix;
use App\Routing\LocalizedPath;
use App\Service\ContributionReceipt;
use App\Service\ContributionStubInterface;
use Doctrine\DBAL\Exception\TableNotFoundException;
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
 * Contribution wizards.
 *
 * @see docs/specs/moderation-and-contribution.md §1
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
final class ContributeController extends AbstractController
{
    public function __construct(
        private readonly ContributionStubInterface $contributionStub,
        private readonly CatalogContributionService $contributions,
        private readonly CatalogFormRegistry $registry,
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

    /**
     * The dedicated climb wizard was folded into /improve on 2026-08-25 (owner):
     * one form per item type, the registry-driven one. Old links and the map's
     * "Add a climb here" still arrive here and are sent on with their camera hint
     * (docs/specs/map-and-search.md §8.1 validates it on the other side).
     */
    #[Route('/add-climb', name: 'add_climb')]
    public function addClimb(Request $request): Response
    {
        // Validated, never trusted (§8.1): junk falls back to the wizard's own centre,
        // a wild zoom is clamped rather than throwing the coordinates away.
        $params = ['type' => ItemType::Climbs->value, 'mode' => 'add'];
        $lat = $request->query->get('lat');
        $lng = $request->query->get('lng');
        if (is_numeric($lat) && is_numeric($lng)
            && (float) $lat >= -90.0 && (float) $lat <= 90.0 && (float) $lng >= -180.0 && (float) $lng <= 180.0) {
            $params['lat'] = $lat;
            $params['lng'] = $lng;
            $z = $request->query->get('z');
            if (is_numeric($z)) {
                $params['z'] = (string) max(3.0, min(18.0, (float) $z));
            }
        }

        return $this->redirectToRoute('improve', $params, Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route(LocalizedPath::VOTE, name: 'vote')]
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

    private function renderUnbound(): Response
    {
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

    /**
     * Materialize-on-edit from a coverage POI.
     *
     * @see docs/specs/osm-data-architecture.md §6
     */
    private function materialize(Request $request, ItemType $type, string $ref, CoverageRepository $coverage, EntityManagerInterface $em): Response
    {
        $existing = $em->getRepository(Item::class)->findOneBy([
            'sourceRef' => $ref,
            'state' => [ItemState::Unverified, ItemState::Verified],
        ]);
        if (null !== $existing && $existing->getLetter() === $type->letter()) {
            return $this->redirectToRoute('improve', ['item' => $existing->getId(), 'type' => $type->value]);
        }

        // docs/specs/coverage-provider.md §4 — A is not in coverage_poi; skip the point lookup.
        $poi = null;
        if (LocationMode::Segment !== $type->locationMode()) {
            [$osmType, $osmId] = explode('/', $ref);
            try {
                $poi = $coverage->detail($osmType, (int) $osmId);
            } catch (TableNotFoundException) {
                $poi = null;
            }
            if (null === $poi || $poi['letter'] !== $type->letter()) {
                return $this->renderUnbound();
            }
        }

        $osmName = trim((string) $request->query->get('name', ''));
        $current = [Item::NAME_FIELD => '' !== $osmName
            ? mb_substr($osmName, 0, 120)
            : (string) ($poi['name'] ?? '')];
        $prechosen = SurfaceVocabulary::fromTileClass((string) $request->query->get('surface', ''));
        if (null !== $prechosen) {
            foreach ($this->registry->for($type)->all() as $field) {
                if ('surface' === $field->name && \in_array($prechosen, $field->choices, true)) {
                    $current['surface'] = $prechosen;
                    break;
                }
            }
        }

        $form = $this->createForm(ImproveType::class, null, [
            'catalog_type' => $type,
            'current' => $current,
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
                $srefs = array_values(array_unique(array_filter(
                    explode(',', (string) $request->query->get('srefs', '')),
                    static fn (string $r): bool => 1 === preg_match('~^way/\d{1,16}$~', $r),
                )));
                $receipt = $this->contributionStub->submit('add', [
                    'type' => $type->value,
                    '_osm_ref' => $ref,
                    '_ways_spanned' => implode(',', \array_slice($srefs, 0, 120)),
                ] + $data, $user);

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

    /** mode=add arm of /improve. */
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
    public function improve(Request $request, EntityManagerInterface $em, CoverageRepository $coverage): Response
    {
        // docs/specs/moderation-and-contribution.md §1.4 — non-numeric item is unbound, never a 400.
        $item = null;
        $itemParam = (string) $request->query->get('item', '');
        $typeParam = (string) $request->query->get('type', '');
        $requestedType = '' === $typeParam ? null : ItemType::fromParam($typeParam);

        // R lives in recommended_route — do not bind its id against item (IDOR).
        if (ItemType::QualityRides !== $requestedType && ctype_digit($itemParam)) {
            // docs/specs/catalog-data-model.md §4 — served states only; curator/submitter may open submitted.
            $states = [ItemState::Unverified, ItemState::Verified];
            $user = $this->getUser();
            if ($this->isGranted('ROLE_CURATOR')
                || ($user instanceof User && $em->getConnection()->fetchOne(
                    'SELECT 1 FROM submission WHERE item_id = :id AND user_id = :uid LIMIT 1',
                    ['id' => (int) $itemParam, 'uid' => (int) $user->getId()],
                ))) {
                $states[] = ItemState::Submitted;
            }
            $item = $em->getRepository(Item::class)->findOneBy([
                'id' => (int) $itemParam,
                'state' => $states,
            ]);
            if (null !== $item && null !== $requestedType && $item->getLetter() !== $requestedType->letter()) {
                $item = null;
            }
        }

        // docs/specs/moderation-and-contribution.md §1.1 — mode=add.
        // Climbs included since 2026-08-25: the old /add-climb wizard redirects here (owner).
        if (null === $item && 'add' === (string) $request->query->get('mode', '')
            && null !== $requestedType
            && ItemType::QualityRides !== $requestedType) {
            return $this->addPlace($request, $requestedType);
        }

        // docs/specs/osm-data-architecture.md §6
        $ref = (string) $request->query->get('ref', '');
        if (null === $item && null !== $requestedType
            && !\in_array($requestedType, [ItemType::Climbs, ItemType::QualityRides], true)
            && 1 === preg_match('~^(node|way)/\d{1,16}$~', $ref)) {
            return $this->materialize($request, $requestedType, $ref, $coverage, $em);
        }

        if (null === $item) {
            return $this->renderUnbound();
        }

        $type = ItemType::fromParam($item->getLetter());
        $current = ['name' => $item->getName()] + $item->getAttributes();

        // docs/specs/moderation-and-contribution.md §7.3b — revise the open submission, do not file a second.
        if (null !== $item->getId() && $this->getUser() instanceof User) {
            /** @var User $rider */
            $rider = $this->getUser();
            $openSubmission = $this->contributions->openSubmissionFor((int) $item->getId(), $rider);
            if (null !== $openSubmission) {
                foreach ($openSubmission->getChanges() as $field => $pair) {
                    if (\is_array($pair) && \array_key_exists('now', $pair) && null !== $pair['now']) {
                        $current[$field] = $pair['now'];
                    }
                }
            }
        }
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

        $geom = json_decode((string) $item->getGeom(), true);
        $point = \is_array($geom) ? ($geom['coordinates'] ?? null) : null;
        while (\is_array($point) && \is_array($point[0] ?? null)) {
            $point = $point[0];
        }
        $itemLat = \is_array($point) && is_numeric($point[1] ?? null) ? (float) $point[1] : null;
        $itemLng = \is_array($point) && is_numeric($point[0] ?? null) ? (float) $point[0] : null;

        // A curator who came here from the queue's ✎ is correcting a detail; the
        // decision itself lives on the map, so the review step points there
        // instead of leaving a greyed-out Submit (owner 2026-08-25).
        $pendingSubmissionId = null;
        if ($this->isGranted('ROLE_CURATOR')) {
            $found = $em->getConnection()->fetchOne(
                "SELECT id FROM submission WHERE item_id = :id AND status IN ('pending', 'needs_info') ORDER BY id DESC LIMIT 1",
                ['id' => (int) $item->getId()],
            );
            $pendingSubmissionId = false === $found ? null : (int) $found;
        }

        return $this->render('contribute/improve.html.twig', [
            'page_title' => 'meta.improve_title',
            'page_description' => 'meta.improve_description',
            'nav_active' => 'improve',
            'item_type' => $type,
            'unbound' => false,
            'edit_name' => $item->getName(),
            'item_lat' => $itemLat,
            'item_lng' => $itemLng,
            'receipt' => null,
            'form' => $form,
            'current' => $current,
            'pending_submission_id' => $pendingSubmissionId,
        ]);
    }
}
