<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\CatalogFormRegistry;
use App\Catalog\ConfirmationStance;
use App\Catalog\Entity\Item;
use App\Catalog\Import\OsmLinker;
use App\Catalog\ItemState;
use App\Catalog\ItemType;
use App\Catalog\LocationMode;
use App\Catalog\RoadType;
use App\Catalog\ServiceKind;
use App\Catalog\SurfaceVocabulary;
use App\Community\ItemConfirmationService;
use App\Contribution\BikeWayLocator;
use App\Contribution\BikeWayReading;
use App\Contribution\CatalogContributionService;
use App\Coverage\CoverageRepository;
use App\Entity\User;
use App\Form\ImproveType;
use App\Form\VoteType;
use App\Media\Entity\MediaUpload;
use App\Media\PhotoLocationConfirmation;
use App\Media\PhotoValidator;
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
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * The OSM objects near a point, for the wizard's "is this already in
     * OpenStreetMap?" question.
     *
     * **Curator-only, because only a curator is asked.** Approval of a new
     * place needs that answer (catalog-data-model.md §5b), and a curator's own
     * place is approved at submit time, so the question moves into the wizard
     * for them. A rider never sees it, and this endpoint tells them nothing:
     * it is the same list the moderation card shows, which is curator-facing.
     *
     * A candidate another served row already claims carries that row's
     * `itemId`. It is returned rather than filtered out, and with the id
     * rather than a bare flag, because the useful answer to "this is already
     * here" is the entry itself: the curator opens it instead of adding a
     * second one (owner 2026-09-12).
     */
    /**
     * How far a point is from a way a bike may ride, for the scenic-view
     * warning in the add form (docs/specs/scenic-views.md). The intake asks the
     * same question again on submit, so this only decides what the form shows.
     */
    #[Route('/contribute/bike-way-near', name: 'contribute_bike_way_near', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function bikeWayNear(Request $request, BikeWayLocator $bikeWays): Response
    {
        $lat = $request->query->get('lat');
        $lng = $request->query->get('lng');
        if (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return $this->json(BikeWayReading::unknown()->toArray());
        }

        return $this->json($bikeWays->nearest((float) $lat, (float) $lng)->toArray());
    }

    /**
     * How many rider photos an item's scenic view would hide with its pin at
     * `lat`, `lng`, for the edit form's warning before the move is saved
     * (docs/specs/scenic-views.md §8). `{hidden: n}`; 0 for any other letter
     * and for a point that is not one.
     */
    #[Route('/contribute/pin-move-photos', name: 'contribute_pin_move_photos', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function pinMovePhotos(Request $request, EntityManagerInterface $em, PhotoLocationConfirmation $confirmation): Response
    {
        $item = $em->find(Item::class, $request->query->getInt('item'));
        if (null === $item) {
            throw $this->createNotFoundException();
        }
        $lat = $request->query->get('lat');
        $lng = $request->query->get('lng');
        $none = ['hidden' => 0, 'farthestM' => null, 'withinM' => PhotoValidator::MAX_CAMERA_DISTANCE_M];
        if (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return $this->json($none);
        }

        return $this->json(['withinM' => PhotoValidator::MAX_CAMERA_DISTANCE_M] + $confirmation->moveEffect($item, (float) $lat, (float) $lng) + $none);
    }

    #[Route('/contribute/osm-nearby', name: 'contribute_osm_nearby', methods: ['GET'])]
    #[IsGranted('ROLE_CURATOR')]
    public function osmNearby(Request $request, OsmLinker $linker): Response
    {
        $lat = $request->query->get('lat');
        $lng = $request->query->get('lng');
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return $this->json(['candidates' => []]);
        }

        // An unknown type falls back to the default rather than 404ing, the
        // same rule the wizard itself follows: this is a suggestion list, not
        // an identifier.
        $letter = ItemType::fromParam((string) $request->query->get('type', ''))->letter();
        $candidates = array_map(
            static function (array $c) use ($linker, $letter): array {
                $itemId = $linker->claimedBy($c['ref'], $letter);

                return $c + ['taken' => null !== $itemId, 'itemId' => $itemId];
            },
            $linker->nearby($letter, (float) $lat, (float) $lng),
        );

        return $this->json(['candidates' => $candidates]);
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

    /**
     * The OSM values the map handed the wizard, in the form's own vocabulary.
     *
     * Read from the query rather than re-fetched: the map already has the tile
     * open and knows what it drew, and a second lookup here could disagree with
     * what the rider was looking at. Anything OSM says that the form cannot
     * express is left out; a missing baseline is honest, a guessed one is not.
     *
     * @return array<string, string>
     */
    private static function osmBaseline(Request $request): array
    {
        $was = [];
        $surface = SurfaceVocabulary::fromTileClass((string) $request->query->get('osm_surface', ''));
        if (null !== $surface) {
            $was['surface'] = $surface;
        }
        $roadType = RoadType::fromHighway((string) $request->query->get('osm_highway', ''));
        if (null !== $roadType) {
            $was['roadType'] = $roadType;
        }

        return $was;
    }

    /**
     * The no-target page.
     *
     * `$reason` says WHY, because a link that named a target and still landed
     * here is not answered by "pick a place to improve": the rider picked one
     * (owner-reported 2026-09-16, following a route's "+ add" row).
     * - `route`: a `type=R` link. Route data is not edited here by anyone
     *   (docs/specs/edit-items/R-quality-rides.md).
     * - `missing`: a target that named an id or a ref this rider cannot open:
     *   gone, not served, not theirs, or the wrong type for the id.
     * - null: a bare /improve, where "pick a place" IS the answer.
     */
    private function renderUnbound(?string $reason = null): Response
    {
        return $this->render('contribute/improve.html.twig', [
            'page_title' => 'meta.improve_title',
            'page_description' => 'meta.improve_description',
            'nav_active' => 'improve',
            'item_type' => ItemType::default(),
            'unbound' => true,
            'unbound_reason' => $reason,
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
                return $this->renderUnbound('missing');   // the ref named a point the coverage set does not hold
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
                    /* What OSM held when the rider opened the form, so the
                       submission can say what they changed FROM. Our catalogue
                       held nothing (this is a new item), which is not the same
                       as OSM holding nothing, and without this a rider turning
                       asphalt into gravel looks exactly like one filling in a
                       blank road (owner-reported 2026-08-31). Mapped through
                       the same vocabularies the form offers, so an OSM value we
                       cannot express is simply absent rather than guessed. */
                    '_osm_was' => self::osmBaseline($request),
                    // The curator's OSM answer, under the service's own key so
                    // it cannot be confused with `_osm_ref`, which says this
                    // place IS that object and carries the dedupe rules.
                    '_osm_answer' => $data['osmAnswer'] ?? null,
                ] + $data, $user);

                return $this->renderAddPlace($type, receipt: $receipt, fromOsm: true);
            } catch (TooManyRequestsHttpException) {
                $this->addFlash('error', 'contribute.error.rate_limited');
            } catch (ValidationFailedException $e) {
                foreach ($e->getViolations() as $violation) {
                    $form->addError(new FormError((string) $violation->getMessage()));
                }
            }
        }

        return $this->renderAddPlace($type, form: $form, fromOsm: true);
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

    /**
     * @param bool $fromOsm the place is taken from an OSM node, so its OSM
     *                      question is answered and a curator's own
     *                      submission applies at once
     *                      (moderation-and-contribution.md §1.6)
     */
    private function renderAddPlace(ItemType $type, ?ContributionReceipt $receipt = null, ?FormInterface $form = null, bool $fromOsm = false): Response
    {
        return $this->render('contribute/improve.html.twig', [
            'page_title' => 'meta.improve_title',
            'page_description' => 'meta.improve_description',
            'nav_active' => 'improve',
            'item_type' => $type,
            'unbound' => false,
            'add_mode' => true,
            'from_osm' => $fromOsm,
            // The radius the candidate query actually uses, so the line that
            // tells a curator what the list is cannot drift from it.
            'osm_radius_m' => OsmLinker::LOOSE_M,
            'confirm_offered' => \in_array(ConfirmationStance::Exists, $type->confirmationStances(), true),
            'receipt' => $receipt,
            'form' => $form,
        ]);
    }

    #[Route('/improve', name: 'improve')]
    #[IsGranted('ROLE_USER')]
    public function improve(Request $request, EntityManagerInterface $em, CoverageRepository $coverage, TranslatorInterface $translator, ItemConfirmationService $confirmations): Response
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
            // A link that named a target says why it could not be opened.
            $named = '' !== $itemParam || '' !== $ref;
            return $this->renderUnbound(match (true) {
                ItemType::QualityRides === $requestedType => 'route',
                $named => 'missing',
                default => null,
            });
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
                // A nameless place (a register tap) titles its submission by
                // its type, in the rider's language; the item is not renamed.
                $receipt = $this->contributionStub->submit('improve', [
                    'type' => $type->value,
                    '_item_id' => $item->getId(),
                    '_title_fallback' => $translator->trans('item_type.'.$type->value.'.label'),
                ] + $data, $user);
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

        // Two different facts, never merged into one sentence: a change of
        // your own is amended by what you send next (§7.3b), a stranger's is
        // a second review of possibly the same thing (§7.3c). Whichever one a
        // rider gets, the other would read as a lie.
        $user = $this->getUser();
        $ownPending = $user instanceof User && null !== $this->contributions->openSubmissionFor((int) $item->getId(), $user);
        $otherPendingSince = $ownPending
            ? null
            : $this->contributions->otherPendingSince((int) $item->getId(), $user instanceof User ? $user : null);

        return $this->render('contribute/improve.html.twig', [
            'page_title' => 'meta.improve_title',
            'page_description' => 'meta.improve_description',
            'nav_active' => 'improve',
            'item_type' => $type,
            'unbound' => false,
            'edit_name' => $item->getName(),
            'item_lat' => $itemLat,
            'item_lng' => $itemLng,
            // A scenic view warns before a pin move that hides rider photos (scenic-views.md §8).
            'pin_photos_item' => ItemType::ScenicViews === $type ? $item->getId() : null,
            // The "Mark it confirmed" box needs a row that offers "it exists".
            // A box that asks what the curator already did is noise (owner
            // 2026-09-10): hidden once their own drawer confirmation is on the row.
            'confirm_offered' => \in_array(ConfirmationStance::Exists, ItemConfirmationService::offeredFor($item), true)
                && !($this->getUser() instanceof User && 'drawer' === $confirmations->snapshot($item, $this->getUser())['mineSource']),
            'receipt' => null,
            'form' => $form,
            'current' => $current,
            'own_photo_ids' => $this->ownPhotoIds($current),
            'pending_submission_id' => $pendingSubmissionId,
            'own_pending' => $ownPending,
            'other_pending_since' => $otherPendingSince,
        ]);
    }

    /**
     * Which of this item's photographs the signed-in rider uploaded.
     *
     * A description can be fixed after the fact, but only by the person who
     * wrote it: `MediaController::altText()` is owner-only and would answer 404
     * to anybody else. Offering a field that silently fails is worse than
     * offering none, so the template asks this before it renders one (owner,
     * 2026-08-30: "it should be possible to change the description of an
     * existing image").
     *
     * One query for the whole gallery, and none at all for a signed-out reader.
     *
     * @param array<string, mixed> $current
     *
     * @return list<string>
     */
    private function ownPhotoIds(array $current): array
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return [];
        }

        $gallery = $current['photos'] ?? ($current['photo'] ?? null);
        $gallery = \is_array($gallery) ? (isset($gallery['id']) ? [$gallery] : $gallery) : [];

        $ids = [];
        foreach ($gallery as $photo) {
            if (\is_array($photo) && \is_string($photo['id'] ?? null) && Uuid::isValid($photo['id'])) {
                $ids[] = Uuid::fromString($photo['id']);
            }
        }
        if ([] === $ids) {
            return [];
        }

        /** @var list<MediaUpload> $rows */
        $rows = $this->em->createQuery(
            'SELECT m FROM '.MediaUpload::class.' m WHERE m.id IN (:ids) AND m.userId = :uid',
        )->setParameter('ids', $ids)->setParameter('uid', (int) $user->getId())->getResult();

        return array_map(static fn (MediaUpload $m): string => $m->getId()->toRfc4122(), $rows);
    }
}
