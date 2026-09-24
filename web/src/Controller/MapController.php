<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\BasemapIcons;
use App\Catalog\BikeType;
use App\Catalog\CatalogProvider;
use App\Catalog\CatalogSchemaProvider;
use App\Catalog\ChangeHistoryView;
use App\Catalog\ClosureExpiryService;
use App\Catalog\ConfirmationFreshness;
use App\Catalog\Entity\Item;
use App\Catalog\Import\OsmLinker;
use App\Catalog\ItemType;
use App\Catalog\KindIcons;
use App\Catalog\MapTheme;
use App\Catalog\MapViewMode;
use App\Catalog\RegionBoundaryProvider;
use App\Catalog\RegionRegistryProvider;
use App\Catalog\RideCheckService;
use App\Catalog\RidingStyle;
use App\Catalog\RouteClimbService;
use App\Catalog\RouteRankingService;
use App\Catalog\Season;
use App\Catalog\SurfaceVocabulary;
use App\Coverage\CoverageManifest;
use App\Coverage\RoutesManifest;
use App\Coverage\SurfaceManifest;
use App\Entity\User;
use App\Media\PhotoLocationConfirmation;
use App\Moderation\ModerationScopeProvider;
use App\Moderation\SubmissionQueue;
use App\Scout\ScoutTag;
use App\Security\TwoFactorPolicy;
use App\Service\BaseAreaResolver;
use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Full-screen map shell.
 *
 * @see docs/specs/map-and-search.md §2
 *
 * @api
 */
final class MapController extends AbstractController
{
    /**
     * Scout review: the same `/map` plus a browser-only panel. Ride file never uploaded.
     *
     * @see docs/specs/moderation-and-contribution.md (Scout intake)
     */
    #[Route('/scout/review', name: 'scout_review')]
    #[IsGranted('ROLE_USER')]
    public function scoutReview(Request $request, SubmissionQueue $queue, CatalogSchemaProvider $schema, TranslatorInterface $translator, ModerationScopeProvider $scopeProvider, TwoFactorPolicy $twoFactorPolicy, CoverageManifest $coverage, SurfaceManifest $surface, RoutesManifest $routes, RegionRegistryProvider $regions, CatalogProvider $catalogProvider, SettingsProviderInterface $settings, ConfirmationFreshness $freshness): Response
    {
        return $this->map($request, $queue, $schema, $translator, $scopeProvider, $twoFactorPolicy, $coverage, $surface, $routes, $regions, $catalogProvider, $settings, $freshness, scoutReview: true);
    }

    #[Route('/map', name: 'map')]
    public function map(Request $request, SubmissionQueue $queue, CatalogSchemaProvider $schema, TranslatorInterface $translator, ModerationScopeProvider $scopeProvider, TwoFactorPolicy $twoFactorPolicy, CoverageManifest $coverage, SurfaceManifest $surface, RoutesManifest $routes, RegionRegistryProvider $regions, CatalogProvider $catalogProvider, SettingsProviderInterface $settings, ConfirmationFreshness $freshness, bool $scoutReview = false): Response
    {
        // Three bucket round trips, started together instead of one after the
        // other. Read in sequence they add up, and each carries its own
        // timeout, so the page had no upper bound of its own.
        $coverage->prefetch();
        $surface->prefetch();
        $routes->prefetch();

        $user = $this->getUser();
        $regionRows = array_map(
            static fn (array $r): array => $r + [
                'label' => $translator->trans('region.'.$r['slug'].'.label'),
                'countryLabel' => $translator->trans('region.all_'.strtolower($r['countryCode']).'.label'),
            ],
            $regions->all(),
        );
        $params = [
            'field_schema' => $schema->all(),
            // The fields a rider may ask to have changed on a route, for the
            // drawer's correction box (docs/specs/route-domain.md §7.1).
            'route_fields' => $schema->routeCorrectionFields(),
            // The one category icon set (ItemType::iconSet()); catalog.js and icons.js read it.
            'type_icons' => ItemType::iconSet(),
            // The one kind glyph set (KindIcons::set()); icons.js mints tile icons from it.
            'kind_icons' => KindIcons::set(),
            // The basemap furniture the sprite lacks (BasemapIcons::set()); map-init.js mints it on styleimagemissing.
            'basemap_icons' => BasemapIcons::set(),
            'map_i18n' => $this->mapI18n($translator),
            'regions' => $regionRows,
            'rider_prefs' => [
                // Per-account uid so a shared browser does not inherit another rider's toggles.
                'uid' => $user instanceof User ? $user->getUuid() : null,
                'bikes' => $user instanceof User
                    ? array_map(static fn (BikeType $t): string => $t->value, $user->getBikeTypes())
                    : [],
                'styles' => $user instanceof User
                    ? array_map(static fn (RidingStyle $s): string => $s->value, $user->getRidingStyles())
                    : [],
                'mapMode' => $user instanceof User
                    ? $user->getDefaultMapMode()->value
                    : MapViewMode::Auto->value,
                // Logged-in 'auto' must not fall through to another rider's localStorage.
                'authed' => $user instanceof User,
            ],
            'map_theme' => $user instanceof User
                ? $user->getMapTheme()->value
                : MapTheme::Dark->value,
            'my_area' => $user instanceof User ? [
                'lat' => $user->getBaseLat(),
                'lng' => $user->getBaseLng(),
                'radiusKm' => $user->getBaseRadiusKm(),
                'place' => $user->getBasePlace(),
                'regionIds' => $user->getBaseRegionIds(),
                'countryCodes' => $user->getBaseCountryCodes(),
            ] : null,
            // docs/specs/coverage-provider.md §4: per-country archives, mounted by
            // the client when a country meets the viewport. An empty family omits its control.
            'tiles' => [
                'coverage' => $coverage->countryTiles() ?: new \stdClass(),
                'surface' => $surface->countryTiles() ?: new \stdClass(),
                'gaps' => $surface->gaps(),
                'routes' => $routes->countryTiles() ?: new \stdClass(),
            ],
            'coverage_countries' => $coverage->countryCodes(),
            // data-provider-hierarchy.md §6.7.7 rung 8: a tile point whose
            // check_date is on or after this day has a witness inside the
            // window and drops its "?". One clock for tiles and pins.
            'witness_cutoff' => $freshness->staleBefore(new \DateTimeImmutable())->format('Y-m-d'),
            'voting_live' => 1 === $settings->get(SettingsRegistry::COMMUNITY_VOTING_LIVE),
            'catalog_version' => $catalogProvider->versionTag(),
        ];

        // docs/specs/moderation-and-contribution.md §5.3 — ROLE_CURATOR and completed 2FA; /map is 2FA-bypass.
        if ($user instanceof User && $this->isGranted('ROLE_CURATOR') && !$twoFactorPolicy->requiresSetup($user)) {
            $focus = $request->query->getInt('pending');
            $scope = $scopeProvider->scopeFor($user);
            $params['pending'] = $queue->pendingForMap($scope, $focus > 0 ? $focus : null);
            // A brand-new item is not served yet, so the drawer had nothing to render
            // it with and dumped the raw proposed fields. Hand the curator its would-be
            // feature instead: final form, DEM numbers, same mapper as a live item
            // (owner 2026-08-25).
            foreach ($params['pending'] as $i => $p) {
                if ('new' === $p['type'] && null !== $p['itemId']) {
                    $params['pending'][$i]['preview'] = $catalogProvider->featureForItem($p['itemId'], anyState: true);
                }
            }
            // Do not re-derive with is_granted(): setup-pending curators hold the role without this payload.
            $params['pending_is_curator'] = true;
            // The regions this curator moderates, so a route drawer can offer
            // the desk shortcut only where they may act (route-domain.md §7.1).
            // Per viewer, on this page: never in the publicly cached catalog
            // document (catalog-data-model.md §9.1). null = every region.
            $params['mod_region_ids'] = $scopeProvider->allowedRegionIds($scope);
            $params['gone'] = $catalogProvider->goneForMap($scope);
        } elseif ($user instanceof User) {
            // Rider's own undecided submissions; no curator chrome.
            $params['pending'] = $queue->ownPendingForMap((int) $user->getId());
        }

        // docs/specs/map-and-search.md §8: `?route=<id>` shows the route it names.
        // A route waiting for review is not in the catalog payload, so the page
        // carries that one route, for a curator who may moderate it (the same
        // gate as the pending payload above) and for the rider who proposed it.
        $routeId = $request->query->getInt('route');
        if ($user instanceof User && $routeId > 0 && null !== ($submitted = $catalogProvider->submittedRoute($routeId))
            && $this->mayPreviewRoute($user, $submitted, $scopeProvider, $twoFactorPolicy)) {
            $params['route_preview'] = $submitted['route'];
        }

        $params['scout_review'] = $scoutReview;
        // docs/specs/moderation-and-contribution.md (Scout intake) — one vocabulary, shared with the endpoint.
        $offers = [];
        foreach (ScoutTag::TYPES as $tagType) {
            $offers[$tagType] = ['' => ScoutTag::offerFor($tagType)];
            foreach (array_keys(ScoutTag::DETAIL_LETTERS[$tagType] ?? []) as $detail) {
                $offers[$tagType][(string) $detail] = ScoutTag::offerFor($tagType, $detail);
            }
        }
        $params['scout_tags'] = array_map(static fn (array $byDetail): array => $byDetail[''], $offers);
        $params['scout_details'] = $offers;
        // Surface label → map line class, so a changed dropdown recolours the stretch.
        $params['scout_surface_class'] = SurfaceVocabulary::TO_TILE_CLASS;

        return $this->render('map/index.html.twig', $params);
    }

    /**
     * Who sees a route waiting for review: a curator who may moderate it (the
     * gate of the map's pending payload: ROLE_CURATOR, 2FA set up, the route's
     * region inside their area) and the rider who proposed it.
     *
     * @param array{regionId: int|null, proposedBy: int|null, ...} $submitted
     *
     * @see docs/specs/map-and-search.md §8
     */
    private function mayPreviewRoute(User $user, array $submitted, ModerationScopeProvider $scopeProvider, TwoFactorPolicy $twoFactorPolicy): bool
    {
        if ($submitted['proposedBy'] === $user->getId()) {
            return true;
        }

        return $this->isGranted('ROLE_CURATOR') && !$twoFactorPolicy->requiresSetup($user)
            && $scopeProvider->allowsRegion($scopeProvider->scopeFor($user), $submitted['regionId']);
    }

    /**
     * The climbs a route rides, in order along it, for the route drawer.
     *
     * @see docs/specs/route-domain.md §6.4
     * @see docs/specs/map-and-search.md §6.3
     */
    #[Route('/map/route/{id}/climbs', name: 'map_route_climbs', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function routeClimbs(int $id, Request $request, RouteClimbService $routeClimbs, CatalogProvider $catalogProvider, ModerationScopeProvider $scopeProvider, TwoFactorPolicy $twoFactorPolicy): Response
    {
        return $this->routeListResponse(
            $id, $request, $catalogProvider, $scopeProvider, $twoFactorPolicy,
            static function (bool $allowSubmitted) use ($routeClimbs, $id): ?array {
                $climbs = $routeClimbs->climbsOn($id, $allowSubmitted);

                return null === $climbs ? null : ['climbs' => $climbs];
            },
        );
    }

    /**
     * What is along a route, for the route drawer: the ride check's commons and
     * open-coverage arms on the route's own line (RideCheckService::alongRoute).
     *
     * @see docs/specs/route-domain.md §6.4
     * @see docs/specs/map-and-search.md §6.3
     */
    #[Route('/map/route/{id}/along', name: 'map_route_along', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function routeAlong(int $id, Request $request, RideCheckService $rideCheck, CatalogProvider $catalogProvider, ModerationScopeProvider $scopeProvider, TwoFactorPolicy $twoFactorPolicy): Response
    {
        return $this->routeListResponse(
            $id, $request, $catalogProvider, $scopeProvider, $twoFactorPolicy,
            static fn (bool $allowSubmitted): ?array => $rideCheck->alongRoute($id, $allowSubmitted),
        );
    }

    /**
     * One per-route list for the route drawer. A live route answers anyone and
     * is cached publicly. A route waiting for review answers only whoever may
     * preview it, never cached. Anything else is 404, the same answer as no
     * route at all.
     *
     * @param \Closure(bool): (array<string, mixed>|null) $answer given whether a waiting route may be read
     */
    private function routeListResponse(int $id, Request $request, CatalogProvider $catalogProvider, ModerationScopeProvider $scopeProvider, TwoFactorPolicy $twoFactorPolicy, \Closure $answer): Response
    {
        $user = $this->getUser();
        $submitted = $catalogProvider->submittedRoute($id);
        if (null !== $submitted && (!$user instanceof User || !$this->mayPreviewRoute($user, $submitted, $scopeProvider, $twoFactorPolicy))) {
            throw $this->createNotFoundException();
        }
        $payload = $answer(null !== $submitted);
        if (null === $payload) {
            throw $this->createNotFoundException();
        }

        $json = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        if (null !== $submitted) {
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        }
        $response->setEtag(md5($json));
        $response->setPublic();
        // docs/specs/account-and-auth.md §5: public cache; do not let a session cookie downgrade it.
        $response->setMaxAge(600);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * window.CC_I18N in the request locale.
     *
     * @see docs/specs/map-and-search.md §2
     *
     * @return array<string, mixed>
     */
    private function mapI18n(TranslatorInterface $t): array
    {
        $layers = [
            'surface' => 'item_type.road-surface.label',
            'climbs' => 'item_type.climbs.label',
            'water' => 'item_type.water-food.label',
            'toilets' => 'item_type.public-toilets.label',
            'services' => 'item_type.bike-services.label',
            'stays' => 'item_type.where-to-sleep.label',
            'hazards' => 'item_type.hazards.label',
            'transit' => 'item_type.getting-there.label',
            'shelter' => 'item_type.shelter.label',
            'scenic' => 'item_type.scenic-views.label',
            'history' => 'item_type.history-culture.label',
            'experience' => 'item_type.quality-rides.label',
            'pending' => 'map.pending_review',
            'gone' => 'map.gone_places',
        ];

        $drawer = [
            'type' => 'd_type', 'location' => 'd_location', 'town' => 'd_town', 'province' => 'd_province', 'listed' => 'd_listed',
            'viewDirection' => 'd_view_direction', 'drop' => 'd_drop',
            'bikesAllowed' => 'd_bikes_allowed', 'bikesAllowedFee' => 'd_bikes_allowed_fee',
            'bikesDismount' => 'd_bikes_dismount', 'bikesDismountFee' => 'd_bikes_dismount_fee', 'bikesNotAllowed' => 'd_bikes_not_allowed',
            'crossingTime' => 'd_crossing_time', 'durationH' => 'd_duration_h', 'durationMin' => 'd_duration_min', 'durationHMin' => 'd_duration_h_min',
            'season' => 'd_season', 'ferrySeasonal' => 'd_ferry_seasonal', 'ferryAllYear' => 'd_ferry_all_year', 'serviceHours' => 'd_service_hours',
            'fare' => 'd_fare', 'ferryPaid' => 'd_ferry_paid', 'ferryFree' => 'd_ferry_free', 'ferryBadge' => 'd_ferry_badge',
            'fromFerry' => 'd_from_ferry', 'viaFerryRoute' => 'd_via_ferry_route', 'viaFerryRoutes' => 'd_via_ferry_routes',
            'status' => 'd_status', 'rating' => 'd_rating', 'website' => 'd_website', 'potable' => 'd_potable',
            'verify' => 'd_verify', 'distance' => 'd_distance', 'startsAt' => 'd_starts_at',
            'townsOnRoute' => 'd_towns_on_route', 'surfaces' => 'd_surfaces', 'submittedBy' => 'd_submitted_by', 'itemToday' => 'd_item_today',
            'age' => 'd_age', 'where' => 'd_where', 'place' => 'd_place', 'wallonia' => 'd_wallonia',
            'close' => 'd_close',
            'officialRegistry' => 'd_official_registry', 'confirmed' => 'd_confirmed', 'simulated' => 'd_simulated',
            'drinkingWater' => 'd_drinking_water', 'headlineDrinking' => 'd_headline_drinking', 'headlineFood' => 'd_headline_food',
            'potableOsm' => 'd_potable_osm', 'potableOsmNo' => 'd_potable_osm_no',
            'potableOsmUnknown' => 'd_potable_osm_unknown', 'potableOsmImplied' => 'd_potable_osm_implied',
            'verifyWater' => 'd_verify_water',
            'proposedVerify' => 'd_proposed_verify', 'estimateMethod' => 'd_estimate_method',
            'contributedGpx' => 'd_contributed_gpx', 'srcAuto' => 'd_src_auto', 'srcRider' => 'd_src_rider', 'srcManual' => 'd_src_manual',
            'lnkWikipedia' => 'd_lnk_wikipedia',
            'linksUnsafe' => 'd_links_unsafe', 'linksUnknown' => 'd_links_unknown',
            'communityReport' => 'd_community_report', 'reportPhoto' => 'd_report_photo',
            'source' => 'd_source', 'editItem' => 'd_edit_item', 'fixLocation' => 'd_fix_location',
            'voteRound' => 'd_vote_round', 'downloadGpx' => 'd_download_gpx',
            'proposedChange' => 'd_proposed_change', 'history' => 'd_history', 'initialEntry' => 'd_initial_entry',
            'historyAuto' => 'd_history_auto',
            'surfCycleway' => 'legend_cycleway', 'surfPaved' => 'legend_paved',
            'surfGravel' => 'legend_gravel', 'surfCobbles' => 'legend_cobbles',
            'surfDirt' => 'legend_dirt', 'surfRock' => 'legend_rock',
            'surfUnverified' => 'legend_unverified',
            'gapsTitle' => 'd_gaps_title', 'gapsUnrecorded' => 'd_gaps_unrecorded',
            'gapsShare' => 'd_gaps_share', 'gapsRoads' => 'd_gaps_roads',
            'gapsHint' => 'd_gaps_hint',
            'surface' => 'd_surface', 'roadType' => 'd_road_type',
            'surfaceConfirm' => 'd_surface_confirm', 'srcScout' => 'd_src_scout',
            'smoothness' => 'd_smoothness', 'mtbScale' => 'd_mtb_scale',
            'notRecorded' => 'd_not_recorded',
            'length' => 'd_length',
            'asDescribed' => 'd_as_described', 'surfaceCfQ' => 'd_surface_cf_q',
            'surfaceCfA' => 'd_surface_cf_a', 'notAsDescribed' => 'd_not_as_described',
            'cfUseEditQ' => 'd_cf_use_edit_q', 'cfUseEdit' => 'd_cf_use_edit', 'alsoConfirm' => 'd_also_confirm',
            'routeNetwork' => 'd_route_network', 'routeRef' => 'd_route_ref',
            'routesHere' => 'd_routes_here', 'routeSurfaceHint' => 'd_route_surface_hint',
            'knoopTitle' => 'd_knoop_title', 'knoopHint' => 'd_knoop_hint',
            'netIcn' => 'd_net_icn', 'netNcn' => 'd_net_ncn', 'netRcn' => 'd_net_rcn',
            'netLcn' => 'd_net_lcn', 'netMtb' => 'd_net_mtb', 'netOther' => 'd_net_other',
            'scoutTag_resupply' => 'd_scout_tag_resupply', 'scoutTag_closure' => 'd_scout_tag_closure',
            'scoutTag_surface' => 'd_scout_tag_surface', 'scoutTag_notice' => 'd_scout_tag_notice',
            'scoutTag_scenery' => 'd_scout_tag_scenery', 'scoutTag_other' => 'd_scout_tag_other',
            'scoutLetter_A' => 'd_scout_letter_A', 'scoutLetter_B' => 'd_scout_letter_B',
            'scoutLetter_D' => 'd_scout_letter_D', 'scoutLetter_E' => 'd_scout_letter_E',
            'scoutLetter_G' => 'd_scout_letter_G', 'scoutLetter_P' => 'd_scout_letter_P',
            'scoutLetter_Q' => 'd_scout_letter_Q',
            'scoutApprove' => 'd_scout_approve', 'scoutSent' => 'd_scout_sent',
            'scoutSending' => 'd_scout_sending', 'scoutNamePh' => 'd_scout_name_ph',
            'scoutNeedName' => 'd_scout_need_name', 'scoutSendFailed' => 'd_scout_send_failed',
            'scoutBadFile' => 'd_scout_bad_file', 'scoutNoTags' => 'd_scout_no_tags',
            'scoutNeedFit' => 'd_scout_need_fit',
            'scoutBundleBad' => 'd_scout_bundle_bad', 'scoutBundleVersion' => 'd_scout_bundle_version',
            'scoutBundleTooLarge' => 'd_scout_bundle_too_large', 'scoutBundlePhotos' => 'd_scout_bundle_photos',
            'scoutBundlePhotoRemove' => 'd_scout_bundle_photo_remove', 'scoutBundlePhotosFailed' => 'd_scout_bundle_photos_failed',
            'scoutBundleUnmatched' => 'd_scout_bundle_unmatched',
            'scoutAddPhoto' => 'd_scout_add_photo', 'scoutPhotoAttached' => 'd_scout_photo_attached', 'scoutPhotosAttached' => 'd_scout_photos_attached',
            'scoutRadar' => 'd_scout_radar',
            'scoutPassNoFix' => 'd_scout_pass_nofix',
            'scoutCloseUnsent' => 'd_scout_close_unsent', 'scoutNoFix' => 'd_scout_no_fix',
            'scoutStretchToEnd' => 'd_scout_stretch_to_end',
            'scoutSetEnd' => 'd_scout_set_end', 'scoutKeepEnd' => 'd_scout_keep_end',
            'scoutEndFirst' => 'd_scout_end_first', 'scoutPickEnd' => 'd_scout_pick_end',
            'scoutPickOnRide' => 'd_scout_pick_on_ride', 'scoutPickAfterStart' => 'd_scout_pick_after_start',
            'scoutNeedEnd' => 'd_scout_need_end',
            'scoutDescribe' => 'd_scout_describe', 'scoutRemove' => 'd_scout_remove',
            'scoutBareSurface' => 'd_scout_bare_surface', 'scoutShowBare' => 'd_scout_show_bare',
            'scoutBareTitle' => 'd_scout_bare_title', 'scoutBareInside' => 'd_scout_bare_inside',
            'scoutEndAtBare' => 'd_scout_end_at_bare',
            'scoutPickStop' => 'd_scout_pick_stop',
            'scoutSendAll' => 'd_scout_send_all', 'scoutSendEverything' => 'd_scout_send_everything',
            'roadMain' => 'd_road_main', 'roadLocal' => 'd_road_local',
            'roadResidential' => 'd_road_residential', 'roadTrack' => 'd_road_track',
            'roadPath' => 'd_road_path', 'roadCycleway' => 'd_road_cycleway',
            'traffic' => 'd_traffic', 'assumedAria' => 'd_assumed_aria',
            'trafficWhyCycleway' => 'd_traffic_why_cycleway',
            'trafficWhyResidential' => 'd_traffic_why_residential',
            'trafficWhyTrack' => 'd_traffic_why_track',
            'trafficWhyMain' => 'd_traffic_why_main',
            'shapeOnMap' => 'd_shape_on_map', 'shapeBefore' => 'd_shape_before', 'shapeAfter' => 'd_shape_after',
            'itemProposed' => 'd_item_proposed',
            'fRoute' => 'd_f_route', 'fGrad' => 'd_f_grad', 'fSteep' => 'd_f_steep',
            'fCorrection' => 'd_f_correction',
            'recentChanges' => 'd_recent_changes', 'modNotePh' => 'd_mod_note_ph', 'approve' => 'd_approve',
            'needsInfo' => 'd_needs_info', 'reject' => 'd_reject', 'modKeys' => 'd_mod_keys',
            'decisionErr' => 'd_decision_err', 'decisionRecorded' => 'd_decision_recorded',
            'decisionAsked' => 'd_decision_asked', 'needsInfoNote' => 'd_needs_info_note',
            'waitingOnRider' => 'd_waiting_on_rider', 'youAsked' => 'd_you_asked', 'riderReplied' => 'd_rider_replied',
            'priorRejected' => 'd_prior_rejected',
            'share' => 'd_share', 'shareHint' => 'd_share_hint', 'shareCopied' => 'd_share_copied',
            'reportPage' => 'd_report_page',
            'photoAlt' => 'd_photo_alt', 'photoDesc' => 'd_photo_desc', 'photoDistance' => 'd_photo_distance',
            'photoNoGps' => 'd_photo_no_gps', 'photoKeep' => 'd_photo_keep',
            'photoHiddenTitle' => 'd_photo_hidden_title', 'photoHiddenWhy' => 'd_photo_hidden_why',
            'photoHiddenNoGps' => 'd_photo_hidden_no_gps', 'photoHiddenTooFar' => 'd_photo_hidden_too_far',
            'photoHiddenPinMoved' => 'd_photo_hidden_pin_moved', 'photoHiddenPinMovedConfirmed' => 'd_photo_hidden_pin_moved_confirmed',
            'pinMoveHidesOne' => 'd_pin_move_hides_one', 'pinMoveHidesMany' => 'd_pin_move_hides_many',
            'photoHiddenTakenHere' => 'd_photo_hidden_taken_here', 'photoHiddenFailed' => 'd_photo_hidden_failed',
            'photoOpen' => 'd_photo_open',
            'anonCredit' => 'anon_credit',
            'photosNone' => 'd_photos_none', 'photosOne' => 'd_photos_one',
            'photosMany' => 'd_photos_many',
            'rodeThis' => 'd_rode_this', 'bikeTypePh' => 'd_bike_type_ph', 'recommend' => 'd_recommend',
            'vote' => 'd_vote', 'suggestCorrection' => 'd_suggest_correction', 'optionalDetail' => 'd_optional_detail',
            'markParts' => 'd_mark_parts', 'send' => 'd_send', 'loginRate' => 'd_login_rate',
            'ridesProgress' => 'd_rides_progress', 'voteOne' => 'd_vote_one', 'voteMany' => 'd_vote_many',
            'youRode' => 'd_you_rode', 'votedSeason' => 'd_voted_season',
            'reasonMetadata' => 'd_reason_metadata', 'whichDetail' => 'd_which_detail',
            'shouldBe' => 'd_should_be', 'notSet' => 'd_not_set', 'pickDetail' => 'd_pick_detail',
            'curatorNote' => 'd_curator_note', 'editOnDesk' => 'd_edit_on_desk',
            'reasonBroken' => 'd_reason_broken', 'reasonPrivacy' => 'd_reason_privacy',
            'reasonDuplicate' => 'd_reason_duplicate', 'reasonNotRideable' => 'd_reason_notrideable',
            'reasonOther' => 'd_reason_other',
            'waterQ' => 'd_water_q', 'hereQ' => 'd_here_q', 'notPotable' => 'd_not_potable',
            'waterA' => 'd_water_a', 'hereA' => 'd_here_a',
            'confirmHere' => 'd_confirm_here', 'confirmedOne' => 'd_confirmed_one', 'confirmedMany' => 'd_confirmed_many',
            'confirmedCurator' => 'd_confirmed_curator', 'confirmedCuratorMany' => 'd_confirmed_curator_many',
            'osmBroken' => 'd_osm_broken', 'osmClosed' => 'd_osm_closed', 'osmGone' => 'd_osm_gone',
            'osmSent' => 'd_osm_sent', 'osmAlready' => 'd_osm_already',
            'osmApplied' => 'd_osm_applied', 'osmAppliedVerified' => 'd_osm_applied_verified',
            'osmFailed' => 'd_osm_failed', 'osmLogin' => 'd_osm_login',
            'loginConfirm' => 'd_login_confirm',
            'toastLoginConfirm' => 'd_toast_login_confirm', 'toastThanks' => 'd_toast_thanks',
            'toastErr' => 'd_toast_err', 'toastLoginRate' => 'd_toast_login_rate', 'toastCurator' => 'd_toast_curator',
            'toastVerified' => 'd_toast_verified', 'toastRecorded' => 'd_toast_recorded',
            'toastModeLift' => 'd_toast_mode_lift',
            'toastShownAnyway' => 'd_toast_shown_anyway', 'toastFilterToo' => 'd_toast_filter_too',
            'toastLimit' => 'd_toast_limit', 'toastOpenRoute' => 'd_toast_open_route',
            'pickBikeRode' => 'd_pick_bike_rode', 'pickBikeVote' => 'd_pick_bike_vote',
            'undo' => 'd_undo', 'clear' => 'd_clear', 'done' => 'd_done', 'pointSet' => 'd_point_set',
            'barOne' => 'd_bar_one', 'barMany' => 'd_bar_many', 'marksOne' => 'd_marks_one', 'marksMany' => 'd_marks_many',
            'noPhoto' => 'd_no_photo', 'addPhoto' => 'd_add_photo', 'photoLoading' => 'd_photo_loading', 'add' => 'd_add', 'visitSite' => 'd_visit_site',
            'difficulty' => 'd_difficulty', 'elevation' => 'd_elevation', 'mClimbing' => 'd_m_climbing',
            'climbLength' => 'd_climb_length',
            'fromGpx' => 'd_from_gpx', 'gradProfile' => 'd_grad_profile', 'illustrative' => 'd_illustrative',
            'steepOver' => 'd_steep_over',
            'elevFrom' => 'd_elev_from',
            'openProfile' => 'open_profile', 'gradPerBin' => 'grad_per_bin',
            'elevAria' => 'd_elev_aria', 'sharedBy' => 'd_shared_by', 'viewProfile' => 'd_view_profile',
            'sharedAnon' => 'd_shared_anon', 'steepest' => 'd_steepest',
            'riderSteepest' => 'd_rider_steepest', 'riderRamp' => 'd_rider_ramp',
            'climbFinish' => 'd_climb_finish', 'avgShort' => 'd_avg_short',
            'freshFresh' => 'd_fresh_fresh', 'freshAgeing' => 'd_fresh_ageing', 'freshStale' => 'd_fresh_stale',
            'lastConfirmed' => 'd_last_confirmed', 'thisSeason' => 'd_this_season',
            'city' => 'd_city', 'nearbyH' => 'd_nearby_h',
            'townLoading' => 'd_town_loading', 'wikiText' => 'd_wiki_text', 'cyclingH' => 'd_cycling_h',
            'raceStart' => 'd_race_start', 'raceFinish' => 'd_race_finish', 'raceStartFinish' => 'd_race_start_finish', 'raceVia' => 'd_race_via',
            'raceEditions' => 'd_race_editions', 'raceOnce' => 'd_race_once',
            'routesH' => 'd_routes_h', 'routeKm' => 'd_route_km',
            // docs/specs/map-and-search.md §6.3: climbs on this route.
            'routeClimbsH' => 'd_route_climbs_h', 'routeClimbAt' => 'd_route_climb_at', 'routeClimbsWait' => 'd_route_climbs_wait',
            // docs/specs/map-and-search.md §6.3: places along this route.
            'alongRouteH' => 'd_along_route_h', 'alongRouteWithin' => 'd_along_route_within', 'alongRouteWait' => 'd_along_route_wait',
            'alongRouteCovH' => 'd_along_route_cov_h', 'nothingAlongRoute' => 'd_nothing_along_route',
            'reportText' => 'd_report_text', 'wikiEdited' => 'd_wiki_edited', 'founded' => 'd_founded', 'inhabitants' => 'd_inhabitants', 'circa' => 'd_circa', 'yearBc' => 'd_year_bc',
            'raceStageStart' => 'd_race_stage_start', 'raceStageFinish' => 'd_race_stage_finish', 'raceStageStartFinish' => 'd_race_stage_start_finish', 'nothingHere' => 'd_nothing_here',
            'kindShop' => 'd_kind_shop', 'kindStation' => 'd_kind_station', 'kindPump' => 'd_kind_pump',
            'rideCheck' => 'd_ride_check', 'rideSummary' => 'd_ride_summary',
            'alongRide' => 'd_along_ride', 'rideMeta' => 'd_ride_meta', 'clearRide' => 'd_clear_ride',
            'rideFollows' => 'd_ride_follows', 'kmShared' => 'd_km_shared', 'alongTrackH' => 'd_along_track_h',
            'capped' => 'd_capped', 'kmOff' => 'd_km_off', 'nothingWithin' => 'd_nothing_within',
            'alongTrackCovH' => 'd_along_track_cov_h', 'covArmNote' => 'd_cov_arm_note',
            'noMatch' => 'd_no_match', 'places' => 'd_places',
            'coordinates' => 'd_coordinates', 'goToPoint' => 'd_go_to_point',
            'scopes' => 'd_scopes', 'wholeCountry' => 'd_whole_country', 'region' => 'd_region',
            'scopesMore' => 'd_scopes_more',
            'compassN' => 'd_compass_n', 'compassNe' => 'd_compass_ne',
            'compassE' => 'd_compass_e', 'compassSe' => 'd_compass_se',
            'compassS' => 'd_compass_s', 'compassSw' => 'd_compass_sw',
            'compassW' => 'd_compass_w', 'compassNw' => 'd_compass_nw',
            'compassLabel' => 'd_compass_label', 'compassGroup' => 'd_compass_group',
            'community' => 'd_community', 'unconfirmed' => 'd_unconfirmed', 'showAll' => 'd_show_all',
            'needsCheck' => 'd_needs_check', 'youConfirmed' => 'd_you_confirmed',
            'providerSurvey' => 'd_provider_survey', 'personalConfirmed' => 'd_personal_confirmed', 'personalReclaimed' => 'd_personal_reclaimed',
            'youAnsweredOnForm' => 'd_you_answered_on_form', 'changeAnswer' => 'd_change_answer',
            'stateField' => 'd_state_field', 'stateSubmitted' => 'd_state_submitted', 'stateUnverified' => 'd_state_unverified',
            'stateVerified' => 'd_state_verified', 'stateRejected' => 'd_state_rejected',
        ];

        return [
            'layers' => array_map(static fn (string $id): string => $t->trans($id), $layers),
            'deselectAll' => $t->trans('map.deselect_all'),
            'selectAll' => $t->trans('map.select_all'),
            'groupUtility' => $t->trans('map.dl_group_utility'),
            'groupVotable' => $t->trans('map.dl_group_votable'),
            'groupModeration' => $t->trans('map.dl_group_moderation'),
            'railSearch' => $t->trans('map.rail_search'),
            'railLayers' => $t->trans('map.rail_layers'),
            'railTools' => $t->trans('map.rail_tools'),
            'railKey' => $t->trans('map.rail_key'),
            // Shown INSTEAD of the map when the browser cannot give
            // MapLibre v6 a WebGL2 context. v6 dropped WebGL1 and now
            // throws from the Map constructor rather than returning a
            // map that never paints, so catalog-load.js has something
            // to catch and something to say.
            'gpuUnsupported' => $t->trans('map.gpu_unsupported'),
            'filtersHide' => $t->trans('map.filters_hide'),
            'filtersHideOne' => $t->trans('map.filters_hide_one'),
            'filtersNarrowing' => $t->trans('map.filters_narrowing'),
            'curated' => $t->trans('map.curated'),
            'subEverything' => $t->trans('map.sub_everything'),
            'subConfirmed' => $t->trans('map.sub_confirmed'),
            'allBikes' => $t->trans('map.all_bikes'),
            'allSeasons' => $t->trans('map.all_seasons'),
            'overlayOn' => $t->trans('map.overlay_on'),
            'overlayOff' => $t->trans('map.overlay_off'),
            'zoomForSurfaces' => $t->trans('map.zoom_for_surfaces'),
            'overlayZoomIn' => $t->trans('map.overlay_zoom_in'),
            'pendingReview' => $t->trans('map.pending_review'),
            'login' => $t->trans('nav.login'),
            'searchIn' => $t->trans('map.search_in'),
            'searchEverywhere' => $t->trans('map.search_everywhere'),
            'searchWiden' => $t->trans('map.search_widen'),
            'everywhereLabel' => $t->trans('region.everywhere.label'),
            'myAreaLine' => $t->trans('map.my_area_line'),
            'myAreaLinePlain' => $t->trans('map.my_area_line_plain'),
            'themeToLight' => $t->trans('map.theme_to_light'),
            'themeToDark' => $t->trans('map.theme_to_dark'),
            'myAreaSet' => $t->trans('map.my_area_set'),
            'myAreaSetAnon' => $t->trans('map.my_area_set_anon'),
            'outsideArea' => $t->trans('map.outside_area'),
            'scopeBusy' => $t->trans('map.scope_busy'),
            'scopeMiss' => $t->trans('map.scope_miss'),
            'scopeMissGo' => $t->trans('map.scope_miss_go'),
            'areaDismiss' => $t->trans('map.area_dismiss'),
            'zoomForCoverage' => $t->trans('map.zoom_for_coverage'),
            'pendingFollowsAreas' => $t->trans('map.pending_follows_areas'),
            'pendingYoursAnywhere' => $t->trans('map.pending_yours_anywhere'),
            'seasons' => [
                'spring' => $t->trans('map.season_spring'),
                'summer' => $t->trans('map.season_summer'),
                'autumn' => $t->trans('map.season_autumn'),
                'winter' => $t->trans('map.season_winter'),
            ],
            'bikes' => [
                'Road' => $t->trans('map.bike_road'),
                'Gravel' => $t->trans('map.bike_gravel'),
                'MTB' => $t->trans('map.bike_mtb'),
                'E-bike' => $t->trans('map.bike_ebike'),
                'Handbike' => $t->trans('map.bike_handbike'),
                'Recumbent' => $t->trans('map.bike_recumbent'),
                'Trike' => $t->trans('map.bike_trike'),
                'Tandem' => $t->trans('map.bike_tandem'),
            ],
            'pendingTypes' => [
                'new' => $t->trans('moderate.type.new'),
                'edit' => $t->trans('moderate.type.edit'),
                'hazard' => $t->trans('moderate.type.hazard'),
                'photo' => $t->trans('moderate.type.photo'),
            ],
            'rcChecking' => $t->trans('map.rc_checking'),
            'rcResults' => $t->trans('map.rc_results'),
            'rcClear' => $t->trans('map.rc_clear'),
            'rcError' => $t->trans('map.rc_error'),
            'rcScopeFromRide' => $t->trans('map.rc_scope_from_ride'),
            'mlyLoading' => $t->trans('map.mly_loading'),
            'mlyNone' => $t->trans('map.mly_none'),
            'mlyZoom' => $t->trans('map.mly_zoom'),
            // Locate me (map-init.js): the toasts, and MapLibre's own control
            // labels under the keys its `locale` option reads.
            'locateDenied' => $t->trans('map.locate_denied'),
            'locateFailed' => $t->trans('map.locate_failed'),
            'mapUi' => [
                'GeolocateControl.FindMyLocation' => $t->trans('map.locate_me'),
                'GeolocateControl.LocationNotAvailable' => $t->trans('map.locate_unavailable'),
            ],
            'd' => array_map(static fn (string $id): string => $t->trans('map.'.$id), $drawer) + [
                // Curator duplicate-resolve panel (?finding=<id>). Translated
                // for everyone rather than gated on the role: the bag is one
                // cacheable payload, and three strings are cheaper than a
                // second variant of it.
                'dupeSamePlace' => $t->trans('moderate_data.q_duplicate'),
                'dupeKeepThis' => $t->trans('moderate_data.keep_this'),
                'dupeKeepBoth' => $t->trans('moderate_data.keep_both'),
                // The F field label itself (CatalogFormRegistry), so the OSM
                // Bikes on board row and a stored value share one label and
                // the drawer shows one row, not two.
                'bikesOnBoard' => $t->trans('Bikes on board'),
                // The OSM question on a new place, asked in the drawer where
                // the place is approved (catalog-data-model.md §5b). The
                // desk's own strings, so the two surfaces cannot drift.
                'osmQuestion' => $t->trans('moderate.osm.question'),
                'osmScope' => $t->trans('improve.osm.scope', ['%m%' => OsmLinker::LOOSE_M]),
                'osmNone' => $t->trans('moderate.osm.none'),
                'osmUnnamed' => $t->trans('moderate.osm.unnamed'),
                'osmTipLinked' => $t->trans('moderate.osm.tip_linked'),
                'osmChipNone' => $t->trans('moderate.osm.chip_none'),
                'osmTipNone' => $t->trans('moderate.osm.tip_none'),
                'osmAnsweredLinked' => $t->trans('moderate.osm.answered_linked'),
                'osmAnsweredNone' => $t->trans('moderate.osm.answered_none'),
                'osmRefTaken' => $t->trans('moderate.osm.ref_taken'),
                'osmBadRef' => $t->trans('moderate.osm.bad_ref'),
                'osmUnanswered' => $t->trans('moderate.error.osm_unanswered'),
            ],
        ];
    }

    /**
     * Cacheable catalog JSON.
     *
     * @see docs/specs/catalog-data-model.md §9
     */
    #[Route('/map/catalog.json', name: 'map_catalog', methods: ['GET'])]
    public function catalog(Request $request, CatalogProvider $catalog, ClosureExpiryService $closures): Response
    {
        $closures->sweepOpportunistically();

        $json = $catalog->json();
        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        $response->setEtag(md5($json));
        $response->setPublic();
        // docs/specs/account-and-auth.md §5: public cache; do not let a session cookie downgrade it.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setMaxAge(3600);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * One freshness stamp per region, so a rider's browser can tell whether
     * the regions it is showing moved since its cached catalog was built.
     *
     * Always revalidated (`no-cache` + ETag): it is the one thing that has to
     * be current on a plain reload, and it is two kilobytes, so a 304 is the
     * usual answer. Everything expensive stays behind the hour-long max-age.
     *
     * @see docs/specs/catalog-data-model.md §9.1
     */
    #[Route('/map/catalog/stamps.json', name: 'map_catalog_stamps', methods: ['GET'])]
    public function catalogStamps(Request $request, CatalogProvider $catalog): Response
    {
        $json = json_encode($catalog->regionStamps(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        $response->setEtag(md5($json));
        $response->setPublic();
        // docs/specs/account-and-auth.md §5: public cache; do not let a session cookie downgrade it.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->headers->addCacheControlDirective('no-cache');
        $response->isNotModified($request);

        return $response;
    }

    /**
     * One region's slice of the catalog, in the worldwide document's shapes.
     *
     * The map splices it over the copy it already holds, so a curator's
     * approval reaches the riders of that region at once without anyone
     * redownloading the whole document, and without touching the cache of a
     * rider whose scope is somewhere else. Cacheable for an hour because the
     * URL carries the region's stamp: a new decision mints a new URL.
     *
     * @see docs/specs/catalog-data-model.md §9.1
     */
    #[Route('/map/catalog/region/{rid}.json', name: 'map_catalog_region', requirements: ['rid' => '\d+'], methods: ['GET'])]
    public function catalogRegion(int $rid, Request $request, CatalogProvider $catalog): Response
    {
        $json = $catalog->json($rid);
        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        $response->setEtag(md5($json));
        $response->setPublic();
        // docs/specs/account-and-auth.md §5: public cache; do not let a session cookie downgrade it.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setMaxAge(3600);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Heatmap points, fetched on first On.
     *
     * @see docs/specs/catalog-data-model.md §9
     */
    #[Route('/map/heat.json', name: 'map_heat', methods: ['GET'])]
    public function heat(Request $request, CatalogProvider $catalog): Response
    {
        $json = json_encode($catalog->heat(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        $response->setEtag(md5($json));
        $response->setPublic();
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setMaxAge(3600);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Public change log; empty list, not 404.
     *
     * @see docs/specs/moderation-and-contribution.md §4.1
     */
    #[Route('/map/item/{id}/history', name: 'map_item_history', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function history(int $id, Request $request, ChangeHistoryView $history): Response
    {
        $json = json_encode(['history' => $history->forItem($id)], \JSON_THROW_ON_ERROR);
        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        $response->setEtag(md5($json));
        $response->setPublic();
        // docs/specs/account-and-auth.md §5: public cache; do not let a session cookie downgrade it.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setMaxAge(60);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * The rider photos a scenic view hides, for the curator's "Taken here" block.
     *
     * Curator-only and never cached anywhere: `private, no-store`. A curator
     * outside the item's moderation area gets an empty list, the same answer as
     * an item with nothing hidden, so the drawer shows no button that would be
     * refused. `/map` skips the 2FA setup redirect, so a curator who has not
     * finished setting it up is refused here, as the map page does.
     *
     * @see docs/specs/photo-uploads.md §5g
     * @see docs/specs/scenic-views.md §8
     */
    #[Route('/map/item/{id}/hidden-photos', name: 'map_item_hidden_photos', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_CURATOR')]
    public function hiddenPhotos(int $id, EntityManagerInterface $em, PhotoLocationConfirmation $confirmation, ModerationScopeProvider $scopeProvider, TwoFactorPolicy $twoFactorPolicy): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || $twoFactorPolicy->requiresSetup($user)) {
            throw $this->createAccessDeniedException();
        }
        $item = $em->find(Item::class, $id);
        if (null === $item) {
            throw $this->createNotFoundException();
        }

        $photos = $scopeProvider->allowsRegion($scopeProvider->scopeFor($user), $item->getRegionId())
            ? $confirmation->hiddenPhotos($item)
            : [];

        $response = new JsonResponse(['photos' => $photos]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }

    /**
     * Public best-of ranking for Curated mode.
     *
     * @see docs/specs/route-domain.md §8
     */
    #[Route('/map/best-of', name: 'map_best_of', methods: ['GET'])]
    public function bestOf(Request $request, RouteRankingService $ranking): Response
    {
        $csvEnum = static function (string $raw, callable $tryFrom): array {
            $out = [];
            foreach (explode(',', $raw) as $part) {
                $part = trim($part);
                if ('' === $part || 'all' === $part) {
                    continue;
                }
                $case = $tryFrom($part);
                if (null !== $case) {
                    $out[$part] = $case;
                }
            }

            return array_values($out);
        };
        $seasons = $csvEnum((string) $request->query->get('season', ''), Season::tryFrom(...));
        $bikes = $csvEnum((string) $request->query->get('bike', ''), BikeType::tryFrom(...));
        // docs/specs/map-and-search.md §4.5 — garbage CSV degrades to Everywhere, never a 400.
        $regionIds = [];
        foreach (explode(',', (string) $request->query->get('region', '')) as $part) {
            $part = trim($part);
            if ('' === $part || !ctype_digit($part)) {
                continue;
            }
            $n = (int) $part;
            if ($n > 0 && (string) $n === ltrim($part, '0')) {
                $regionIds[$n] = $n;
            }
        }
        $regionIds = \array_slice(array_values($regionIds), 0, BaseAreaResolver::MAX_REGIONS);
        sort($regionIds);

        $ids = $ranking->bestOf($seasons, $bikes, $regionIds);
        $json = json_encode([
            'season' => array_map(static fn (Season $s): string => $s->value, $seasons),
            'bike' => array_map(static fn (BikeType $b): string => $b->value, $bikes),
            'ids' => $ids,
        ], \JSON_THROW_ON_ERROR);

        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        $response->setEtag(md5($json));
        $response->setPublic();
        // docs/specs/account-and-auth.md §5: public cache; do not let a session cookie downgrade it.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setMaxAge(300);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Cacheable region spotlight; 404 unknown slugs.
     *
     * @see docs/specs/map-and-search.md §4.5
     */
    #[Route('/map/region/{slug}/boundary', name: 'map_region_boundary', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function regionBoundary(string $slug, Request $request, RegionBoundaryProvider $boundaries): Response
    {
        $json = $boundaries->featureJson($slug);
        if (null === $json) {
            return new JsonResponse(['error' => 'Unknown region'], Response::HTTP_NOT_FOUND);
        }

        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        $response->setEtag(md5($json));
        $response->setPublic();
        // docs/specs/account-and-auth.md §5: public cache; do not let a session cookie downgrade it.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setMaxAge(3600);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Cacheable scope-union dim mask; empty scope is 204.
     *
     * @see docs/specs/map-and-search.md §4.5
     */
    #[Route('/map/scope/boundary', name: 'map_scope_boundary', methods: ['GET'])]
    public function scopeBoundary(Request $request, RegionBoundaryProvider $boundaries): Response
    {
        $query = $request->query->all();
        $ridsRaw = \is_string($query['rids'] ?? null) ? $query['rids'] : '';
        $rids = [];
        foreach (explode(',', $ridsRaw) as $part) {
            $part = trim($part);
            if ('' !== $part && ctype_digit($part) && (string) (int) $part === ltrim($part, '0')) {
                $rids[(int) $part] = (int) $part;
            }
        }
        $rids = array_values($rids);
        sort($rids);
        $cc = $query['cc'] ?? null;
        $cc = \is_string($cc) && 1 === preg_match('/^[A-Za-z]{2}$/D', $cc) ? strtoupper($cc) : null;

        $json = $boundaries->unionFeatureJson($rids, $cc);
        if (null === $json) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }
        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        $response->setEtag(md5($json));
        $response->setPublic();
        // docs/specs/account-and-auth.md §5: public cache; do not let a session cookie downgrade it.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setMaxAge(3600);
        $response->isNotModified($request);

        return $response;
    }
}
