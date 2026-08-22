<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\BikeType;
use App\Catalog\CatalogProvider;
use App\Catalog\CatalogSchemaProvider;
use App\Catalog\ChangeHistoryView;
use App\Catalog\ClosureExpiryService;
use App\Catalog\MapTheme;
use App\Catalog\MapViewMode;
use App\Catalog\RegionBoundaryProvider;
use App\Catalog\RegionRegistryProvider;
use App\Catalog\RidingStyle;
use App\Catalog\RouteRankingService;
use App\Catalog\Season;
use App\Coverage\CoverageManifest;
use App\Coverage\RoutesManifest;
use App\Coverage\SurfaceManifest;
use App\Entity\User;
use App\Moderation\ModerationScopeProvider;
use App\Moderation\SubmissionQueue;
use App\Scout\ScoutTag;
use App\Security\TwoFactorPolicy;
use App\Service\BaseAreaResolver;
use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;
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
    public function scoutReview(Request $request, SubmissionQueue $queue, CatalogSchemaProvider $schema, TranslatorInterface $translator, ModerationScopeProvider $scopeProvider, TwoFactorPolicy $twoFactorPolicy, CoverageManifest $coverage, SurfaceManifest $surface, RoutesManifest $routes, RegionRegistryProvider $regions, CatalogProvider $catalogProvider, SettingsProviderInterface $settings): Response
    {
        return $this->map($request, $queue, $schema, $translator, $scopeProvider, $twoFactorPolicy, $coverage, $surface, $routes, $regions, $catalogProvider, $settings, scoutReview: true);
    }

    #[Route('/map', name: 'map')]
    public function map(Request $request, SubmissionQueue $queue, CatalogSchemaProvider $schema, TranslatorInterface $translator, ModerationScopeProvider $scopeProvider, TwoFactorPolicy $twoFactorPolicy, CoverageManifest $coverage, SurfaceManifest $surface, RoutesManifest $routes, RegionRegistryProvider $regions, CatalogProvider $catalogProvider, SettingsProviderInterface $settings, bool $scoutReview = false): Response
    {
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
            // docs/specs/coverage-provider.md §4 — null omits the layer.
            'coverage_url' => $coverage->currentTileUrl(),
            'coverage_countries' => $coverage->countryCodes(),
            // docs/specs/coverage-provider.md §4 — surface PMTiles; null omits the control.
            'surface_tiles_url' => $surface->classifiedUrl(),
            'surface_todo_url' => $surface->todoUrl(),
            'surface_gaps_url' => $surface->gapsUrl(),
            'routes_tiles_url' => $routes->tilesUrl(),
            'voting_live' => 1 === $settings->get(SettingsRegistry::COMMUNITY_VOTING_LIVE),
            'catalog_version' => $catalogProvider->versionTag(),
        ];

        // docs/specs/moderation-and-contribution.md §5.3 — ROLE_CURATOR and completed 2FA; /map is 2FA-bypass.
        if ($user instanceof User && $this->isGranted('ROLE_CURATOR') && !$twoFactorPolicy->requiresSetup($user)) {
            $focus = $request->query->getInt('pending');
            $scope = $scopeProvider->scopeFor($user);
            $params['pending'] = $queue->pendingForMap($scope, $focus > 0 ? $focus : null);
            // Do not re-derive with is_granted(): setup-pending curators hold the role without this payload.
            $params['pending_is_curator'] = true;
            $params['gone'] = $catalogProvider->goneForMap($scope);
        } elseif ($user instanceof User) {
            // Rider's own undecided submissions; no curator chrome.
            $params['pending'] = $queue->ownPendingForMap((int) $user->getId());
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

        return $this->render('map/index.html.twig', $params);
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
            'status' => 'd_status', 'rating' => 'd_rating', 'website' => 'd_website', 'potable' => 'd_potable',
            'verify' => 'd_verify', 'distance' => 'd_distance', 'startsAt' => 'd_starts_at',
            'townsOnRoute' => 'd_towns_on_route', 'surfaces' => 'd_surfaces', 'submittedBy' => 'd_submitted_by', 'itemToday' => 'd_item_today',
            'age' => 'd_age', 'where' => 'd_where', 'place' => 'd_place', 'wallonia' => 'd_wallonia',
            'close' => 'd_close',
            'officialRegistry' => 'd_official_registry', 'confirmed' => 'd_confirmed', 'simulated' => 'd_simulated',
            'drinkingWater' => 'd_drinking_water', 'headlineDrinking' => 'd_headline_drinking',
            'potableOsm' => 'd_potable_osm', 'potableOsmNo' => 'd_potable_osm_no', 'verifyWater' => 'd_verify_water',
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
            'scoutLetter_A' => 'd_scout_letter_A', 'scoutLetter_C' => 'd_scout_letter_C',
            'scoutLetter_D' => 'd_scout_letter_D', 'scoutLetter_F' => 'd_scout_letter_F',
            'scoutLetter_H' => 'd_scout_letter_H', 'scoutLetter_I' => 'd_scout_letter_I',
            'scoutLetter_J' => 'd_scout_letter_J',
            'scoutApprove' => 'd_scout_approve', 'scoutSent' => 'd_scout_sent',
            'scoutSending' => 'd_scout_sending', 'scoutNamePh' => 'd_scout_name_ph',
            'scoutNeedName' => 'd_scout_need_name', 'scoutSendFailed' => 'd_scout_send_failed',
            'scoutBadFile' => 'd_scout_bad_file', 'scoutNoTags' => 'd_scout_no_tags',
            'scoutNeedFit' => 'd_scout_need_fit',
            'scoutAddPhoto' => 'd_scout_add_photo', 'scoutPhotoAttached' => 'd_scout_photo_attached', 'scoutPhotosAttached' => 'd_scout_photos_attached',
            'scoutRadar' => 'd_scout_radar',
            'scoutPassNoFix' => 'd_scout_pass_nofix',
            'scoutCloseUnsent' => 'd_scout_close_unsent', 'scoutNoFix' => 'd_scout_no_fix',
            'scoutStretchToEnd' => 'd_scout_stretch_to_end',
            'scoutDescribe' => 'd_scout_describe', 'scoutRemove' => 'd_scout_remove',
            'scoutBareSurface' => 'd_scout_bare_surface',
            'scoutSendAll' => 'd_scout_send_all',
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
            'photoAlt' => 'd_photo_alt', 'photoDistance' => 'd_photo_distance',
            'photoNoGps' => 'd_photo_no_gps', 'photoKeep' => 'd_photo_keep',
            'photoOpen' => 'd_photo_open',
            'anonCredit' => 'anon_credit',
            'photosNone' => 'd_photos_none', 'photosOne' => 'd_photos_one',
            'photosMany' => 'd_photos_many',
            'rodeThis' => 'd_rode_this', 'bikeTypePh' => 'd_bike_type_ph', 'recommend' => 'd_recommend',
            'vote' => 'd_vote', 'suggestCorrection' => 'd_suggest_correction', 'optionalDetail' => 'd_optional_detail',
            'markParts' => 'd_mark_parts', 'send' => 'd_send', 'loginRate' => 'd_login_rate',
            'ridesProgress' => 'd_rides_progress', 'voteOne' => 'd_vote_one', 'voteMany' => 'd_vote_many',
            'youRode' => 'd_you_rode', 'votedSeason' => 'd_voted_season',
            'reasonBroken' => 'd_reason_broken', 'reasonPrivacy' => 'd_reason_privacy',
            'reasonDuplicate' => 'd_reason_duplicate', 'reasonNotRideable' => 'd_reason_notrideable',
            'reasonOther' => 'd_reason_other',
            'waterQ' => 'd_water_q', 'hereQ' => 'd_here_q', 'notPotable' => 'd_not_potable',
            'waterA' => 'd_water_a', 'hereA' => 'd_here_a',
            'confirmHere' => 'd_confirm_here', 'confirmedOne' => 'd_confirmed_one', 'confirmedMany' => 'd_confirmed_many',
            'osmBroken' => 'd_osm_broken', 'osmClosed' => 'd_osm_closed', 'osmGone' => 'd_osm_gone',
            'osmSent' => 'd_osm_sent', 'osmAlready' => 'd_osm_already',
            'osmFailed' => 'd_osm_failed', 'osmLogin' => 'd_osm_login',
            'loginConfirm' => 'd_login_confirm',
            'toastLoginConfirm' => 'd_toast_login_confirm', 'toastThanks' => 'd_toast_thanks',
            'toastErr' => 'd_toast_err', 'toastLoginRate' => 'd_toast_login_rate', 'toastCurator' => 'd_toast_curator',
            'toastVerified' => 'd_toast_verified', 'toastRecorded' => 'd_toast_recorded',
            'toastLimit' => 'd_toast_limit', 'toastOpenRoute' => 'd_toast_open_route',
            'pickBikeRode' => 'd_pick_bike_rode', 'pickBikeVote' => 'd_pick_bike_vote',
            'undo' => 'd_undo', 'clear' => 'd_clear', 'done' => 'd_done', 'pointSet' => 'd_point_set',
            'barOne' => 'd_bar_one', 'barMany' => 'd_bar_many', 'marksOne' => 'd_marks_one', 'marksMany' => 'd_marks_many',
            'noPhoto' => 'd_no_photo', 'addPhoto' => 'd_add_photo', 'add' => 'd_add', 'visitSite' => 'd_visit_site',
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
            'city' => 'd_city', 'notesNone' => 'd_notes_none', 'nearbyH' => 'd_nearby_h', 'nothingHere' => 'd_nothing_here',
            'kindShop' => 'd_kind_shop', 'kindStation' => 'd_kind_station', 'kindPump' => 'd_kind_pump',
            'rideCheck' => 'd_ride_check',
            'alongRide' => 'd_along_ride', 'rideMeta' => 'd_ride_meta', 'clearRide' => 'd_clear_ride',
            'rideFollows' => 'd_ride_follows', 'kmShared' => 'd_km_shared', 'alongTrackH' => 'd_along_track_h',
            'capped' => 'd_capped', 'kmOff' => 'd_km_off', 'nothingWithin' => 'd_nothing_within',
            'alongTrackCovH' => 'd_along_track_cov_h', 'covArmNote' => 'd_cov_arm_note',
            'noMatch' => 'd_no_match', 'places' => 'd_places',
            'scopes' => 'd_scopes', 'wholeCountry' => 'd_whole_country', 'region' => 'd_region',
            'scopesMore' => 'd_scopes_more',
            'compassN' => 'd_compass_n', 'compassNe' => 'd_compass_ne',
            'compassE' => 'd_compass_e', 'compassSe' => 'd_compass_se',
            'compassS' => 'd_compass_s', 'compassSw' => 'd_compass_sw',
            'compassW' => 'd_compass_w', 'compassNw' => 'd_compass_nw',
            'compassLabel' => 'd_compass_label', 'compassGroup' => 'd_compass_group',
            'community' => 'd_community', 'showAll' => 'd_show_all',
            'needsCheck' => 'd_needs_check', 'youConfirmed' => 'd_you_confirmed',
            'youAnsweredOnForm' => 'd_you_answered_on_form', 'changeAnswer' => 'd_change_answer',
            'stateField' => 'd_state_field', 'stateSubmitted' => 'd_state_submitted', 'stateUnverified' => 'd_state_unverified',
            'stateVerified' => 'd_state_verified', 'stateRejected' => 'd_state_rejected',
            'suggestedRoute' => 'd_suggested_route', 'start' => 'd_start', 'shape' => 'd_shape',
            'roundtrip' => 'd_roundtrip', 'season' => 'd_season', 'why' => 'd_why', 'note' => 'd_note',
            'popularSeason' => 'd_popular_season', 'fakedNote' => 'd_faked_note', 'fakedSrc' => 'd_faked_src',
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
            'filtersHide' => $t->trans('map.filters_hide'),
            'filtersHideOne' => $t->trans('map.filters_hide_one'),
            'filtersNarrowing' => $t->trans('map.filters_narrowing'),
            'curated' => $t->trans('map.curated'),
            'subEverything' => $t->trans('map.sub_everything'),
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
            'scopeMiss' => $t->trans('map.scope_miss'),
            'scopeMissGo' => $t->trans('map.scope_miss_go'),
            'areaDismiss' => $t->trans('map.area_dismiss'),
            'zoomForCoverage' => $t->trans('map.zoom_for_coverage'),
            'zoomForPlaces' => $t->trans('map.zoom_for_places'),
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
            'd' => array_map(static fn (string $id): string => $t->trans('map.'.$id), $drawer),
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
        // docs/specs/account-and-auth.md §5 — public cache; do not let a session cookie downgrade it.
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
        // docs/specs/account-and-auth.md §5 — public cache; do not let a session cookie downgrade it.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setMaxAge(60);
        $response->isNotModified($request);

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
        // docs/specs/account-and-auth.md §5 — public cache; do not let a session cookie downgrade it.
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
        // docs/specs/account-and-auth.md §5 — public cache; do not let a session cookie downgrade it.
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
        // docs/specs/account-and-auth.md §5 — public cache; do not let a session cookie downgrade it.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setMaxAge(3600);
        $response->isNotModified($request);

        return $response;
    }
}
