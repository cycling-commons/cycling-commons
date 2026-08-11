<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use App\Catalog\BikeType;
use App\Catalog\CatalogProvider;
use App\Catalog\CatalogSchemaProvider;
use App\Catalog\ChangeHistoryView;
use App\Catalog\ClosureExpiryService;
use App\Catalog\MapViewMode;
use App\Catalog\RegionBoundaryProvider;
use App\Catalog\RegionRegistryProvider;
use App\Catalog\RidingStyle;
use App\Catalog\RouteRankingService;
use App\Catalog\Season;
use App\Coverage\CoverageManifest;
use App\Entity\User;
use App\Moderation\ModerationScopeProvider;
use App\Moderation\SubmissionQueue;
use App\Security\TwoFactorPolicy;
use App\Service\BaseAreaResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Serves the full-screen interactive map shell.
 *
 * @api Instantiated by Symfony's router, never referenced from code — `@api`
 *      tells Psalm this (and its actions) is a live entry point, not dead code.
 */
final class MapController extends AbstractController
{
    #[Route('/map', name: 'map')]
    public function map(Request $request, SubmissionQueue $queue, CatalogSchemaProvider $schema, TranslatorInterface $translator, ModerationScopeProvider $scopeProvider, TwoFactorPolicy $twoFactorPolicy, CoverageManifest $coverage, RegionRegistryProvider $regions): Response
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
            // Region registry for the scope selector (map-and-search.md §4.5
            // §4 / §7 Phase 2): id/slug/cc/bbox per region, consumed by
            // window.CCScope. Display labels come from region.<slug>.label.
            // The country rungs that used to ride here as `scope_countries` are
            // gone: map.js renderScopeChips() now derives them client-side from
            // CC_REGIONS (map-and-search.md §4.5), so the
            // template no longer reads a server-computed list.
            'regions' => $regionRows,
            // Rider preferences ride the page render (map-and-search.md §4.4):
            // value-lists only, [] for anonymous — map.js treats
            // empty as "no prefilter" so anonymous behaviour is unchanged.
            'rider_prefs' => [
                // The rider's public uuid, so client-side toggles can be
                // stored per ACCOUNT: the prefilter's on/off used to live in
                // one global localStorage key, and rider B on a shared browser
                // inherited rider A's "off" (frontend review 2026-08-09 #4).
                // The uuid is the identity every public surface already uses.
                'uid' => $user instanceof User ? $user->getUuid() : null,
                'bikes' => $user instanceof User
                    ? array_map(static fn (BikeType $t): string => $t->value, $user->getBikeTypes())
                    : [],
                'styles' => $user instanceof User
                    ? array_map(static fn (RidingStyle $s): string => $s->value, $user->getRidingStyles())
                    : [],
                // Which view mode the map opens in, step 1 of the load-time
                // precedence.
                // 'auto' — the default, and the only value an anonymous visitor
                // ever sees — hands the decision down to the active region's
                // curatedDefault, then to the global Everything default.
                'mapMode' => $user instanceof User
                    ? $user->getDefaultMapMode()->value
                    : MapViewMode::Auto->value,
                // Whether a profile exists to hang a choice on. Only an
                // ANONYMOUS visitor falls through to localStorage — a logged-in
                // rider sitting on 'auto' has chosen to follow the region, and
                // must not inherit whatever the previous person on a shared
                // device picked.
                'authed' => $user instanceof User,
            ],
            // "My area" base location (map-and-search.md §4.5): the
            // stored coarse point + derived region/country set, or null for
            // anonymous. Anonymous-safe to compute (null, not omitted) — the
            // template only ever emits window.CC_MY_AREA inside the
            // ROLE_USER script block below.
            'my_area' => $user instanceof User ? [
                'lat' => $user->getBaseLat(),
                'lng' => $user->getBaseLng(),
                'radiusKm' => $user->getBaseRadiusKm(),
                'place' => $user->getBasePlace(),
                'regionIds' => $user->getBaseRegionIds(),
                'countryCodes' => $user->getBaseCountryCodes(),
            ] : null,
            // Coverage tiles (coverage-provider.md §4): the
            // current versioned PMTiles URL from the bucket manifest (server-
            // cached 3600 s), or null when the flag is off / the manifest is
            // unreachable — the template only emits CC_COVERAGE_URL when set.
            'coverage_url' => $coverage->currentTileUrl(),
            // Country codes the tile artifact was built for
            // (coverage-provider.md §4): the map turns
            // each into a per-country coverage layer (source-layers <letter>_<cc>);
            // [] falls the client back to a single unsplit layer per letter.
            'coverage_countries' => $coverage->countryCodes(),
        ];

        // Curator-only: hand the pending submissions to the map so the moderation
        // layer can render. Riders never receive this — the template only emits
        // it when `pending` is set. Two gates:
        //   1. ROLE_CURATOR (un-vetted data is a curator capability), AND
        //   2. mandatory 2FA already completed. /map is on the 2FA-setup
        //      enforcer's bypass list (public page, cacheable), so a
        //      setup-pending curator can still reach this page — but they must
        //      NOT receive any curator capability, including this payload,
        //      before finishing 2FA. requiresSetup() is the same policy the
        //      enforcer/login handler use.
        if ($user instanceof User && $this->isGranted('ROLE_CURATOR') && !$twoFactorPolicy->requiresSetup($user)) {
            // ?pending=<id> is the desk's "review on the map" link. It carries
            // needs-info rows too, which the general layer leaves out — see
            // SubmissionQueue::pendingForMap().
            $focus = $request->query->getInt('pending');
            $params['pending'] = $queue->pendingForMap($scopeProvider->scopeFor($user), $focus > 0 ? $focus : null);
        }

        return $this->render('map/index.html.twig', $params);
    }

    /**
     * The window.CC_I18N bundle: every string map.js renders itself, in the
     * request locale. Layer labels reuse item_type.*.label so the rail can
     * never drift from the improve form / drawer wording; drawer strings live
     * under `d`. English fallbacks stay inline in map.js, so the map still
     * works standalone (or with a stale bundle).
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
        ];

        // Drawer namespace: JS-side name => map.d_* translation id.
        $drawer = [
            'type' => 'd_type', 'town' => 'd_town', 'province' => 'd_province', 'listed' => 'd_listed',
            'status' => 'd_status', 'rating' => 'd_rating', 'website' => 'd_website', 'potable' => 'd_potable',
            'verify' => 'd_verify', 'distance' => 'd_distance', 'startsAt' => 'd_starts_at',
            'townsOnRoute' => 'd_towns_on_route', 'surfaces' => 'd_surfaces', 'submittedBy' => 'd_submitted_by', 'itemToday' => 'd_item_today',
            'age' => 'd_age', 'where' => 'd_where', 'place' => 'd_place', 'wallonia' => 'd_wallonia',
            'officialRegistry' => 'd_official_registry', 'confirmed' => 'd_confirmed', 'simulated' => 'd_simulated',
            'drinkingWater' => 'd_drinking_water', 'headlineDrinking' => 'd_headline_drinking',
            'potableOsm' => 'd_potable_osm', 'potableOsmNo' => 'd_potable_osm_no', 'verifyWater' => 'd_verify_water',
            'proposedVerify' => 'd_proposed_verify', 'estimateMethod' => 'd_estimate_method',
            'contributedGpx' => 'd_contributed_gpx', 'srcAuto' => 'd_src_auto', 'srcRider' => 'd_src_rider',
            'communityReport' => 'd_community_report', 'reportPhoto' => 'd_report_photo',
            'source' => 'd_source', 'editItem' => 'd_edit_item', 'fixLocation' => 'd_fix_location',
            'voteRound' => 'd_vote_round', 'downloadGpx' => 'd_download_gpx',
            'proposedChange' => 'd_proposed_change', 'history' => 'd_history', 'initialEntry' => 'd_initial_entry',
            // Author of an automatic change (a closure that reached its stated
            // window). The endpoint emits a token; the label is translated here.
            'historyAuto' => 'd_history_auto',
            // Surface-tile drawer: the seven canonical class names (shared with
            // the on-map legend, so a tile line and the key read the same word)
            // plus its two row labels.
            'surfCycleway' => 'legend_cycleway', 'surfPaved' => 'legend_paved',
            'surfGravel' => 'legend_gravel', 'surfCobbles' => 'legend_cobbles',
            'surfDirt' => 'legend_dirt', 'surfRock' => 'legend_rock',
            'surfUnverified' => 'legend_unverified',
            'surface' => 'd_surface', 'roadType' => 'd_road_type',
            // Before/after switch on a pending climb's proposed shape.
            'shapeOnMap' => 'd_shape_on_map', 'shapeBefore' => 'd_shape_before', 'shapeAfter' => 'd_shape_after',
            'itemProposed' => 'd_item_proposed',
            // Names for the fields that carry no DISPLAY row of their own (the
            // climb editor's geometry, and the correction note) — without
            // these a curator's diff reads `grad`, `route`, `steep`.
            'fRoute' => 'd_f_route', 'fGrad' => 'd_f_grad', 'fSteep' => 'd_f_steep',
            'fCorrection' => 'd_f_correction',
            'recentChanges' => 'd_recent_changes', 'modNotePh' => 'd_mod_note_ph', 'approve' => 'd_approve',
            'needsInfo' => 'd_needs_info', 'reject' => 'd_reject', 'modKeys' => 'd_mod_keys',
            'decisionErr' => 'd_decision_err', 'decisionRecorded' => 'd_decision_recorded',
            'decisionAsked' => 'd_decision_asked', 'needsInfoNote' => 'd_needs_info_note',
            'waitingOnRider' => 'd_waiting_on_rider', 'youAsked' => 'd_you_asked', 'riderReplied' => 'd_rider_replied',
            // Pending rider photos in the moderation panel
            // (docs/specs/photo-uploads.md §5). The distance string carries a
            // literal {m} the drawer substitutes — the Twig desk list uses the
            // %m%-parameterised moderate.media.distance instead.
            'photoAlt' => 'd_photo_alt', 'photoDistance' => 'd_photo_distance',
            'photoNoGps' => 'd_photo_no_gps', 'photoKeep' => 'd_photo_keep',
            'photoOpen' => 'd_photo_open',
            // How a photo with no credit is captioned: an anonymous rider's
            // upload has an empty credit by design (the uploader rule), and a
            // bare "©" would read as a bug (docs/specs/photo-uploads.md §5).
            'anonCredit' => 'anon_credit',
            // The item history reports a gallery as a count, never as its URLs.
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
            // The steepest-ramp row writes the window it was measured over,
            // per climb, because the field label no longer can (see
            // CatalogFormRegistry's note on that label).
            'steepOver' => 'd_steep_over',
            'elevFrom' => 'd_elev_from',
            // The full climb profile popup (assets/map/climb-profile.js).
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
            // Ride-check coverage arm.
            'alongTrackCovH' => 'd_along_track_cov_h', 'covArmNote' => 'd_cov_arm_note',
            'noMatch' => 'd_no_match', 'places' => 'd_places',
            'scopes' => 'd_scopes', 'wholeCountry' => 'd_whole_country', 'region' => 'd_region',
            // Contextual scope-chip overflow (map-and-search.md §4.5
            // §B, owner fix 2): shown only when a country's region count exceeds
            // the 8-closest cap; opens/focuses the sidebar search box.
            'scopesMore' => 'd_scopes_more',
            // Compass grid (owner request, map-and-search.md §4.5
            // §B "Compass grid layout"): a spelled-out direction word per neighbour
            // chip, since the cell's position alone doesn't reach a screen reader.
            // compassLabel is the aria-label template ('{dir}: {region}');
            // compassGroup names the whole 3x3 grid for the role="group" wrapper.
            'compassN' => 'd_compass_n', 'compassNe' => 'd_compass_ne',
            'compassE' => 'd_compass_e', 'compassSe' => 'd_compass_se',
            'compassS' => 'd_compass_s', 'compassSw' => 'd_compass_sw',
            'compassW' => 'd_compass_w', 'compassNw' => 'd_compass_nw',
            'compassLabel' => 'd_compass_label', 'compassGroup' => 'd_compass_group',
            'community' => 'd_community', 'showAll' => 'd_show_all',
            // The invitation on an approved-but-unconfirmed curated pin.
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
            // The layer rail's three group headings (catalog.js reading order).
            'groupUtility' => $t->trans('map.dl_group_utility'),
            'groupVotable' => $t->trans('map.dl_group_votable'),
            'groupModeration' => $t->trans('map.dl_group_moderation'),
            'curated' => $t->trans('map.curated'),
            'subEverything' => $t->trans('map.sub_everything'),
            'allBikes' => $t->trans('map.all_bikes'),
            'pendingReview' => $t->trans('map.pending_review'),
            'login' => $t->trans('nav.login'),
            // Search scope widening (map-and-search.md §4.5 Phase 2):
            // dynamic search title + the one-tap widen chip. {area} is filled by
            // map.js tpl().
            'searchIn' => $t->trans('map.search_in'),
            'searchEverywhere' => $t->trans('map.search_everywhere'),
            'searchWiden' => $t->trans('map.search_widen'),
            // The "Everywhere" scope's own header/kicker label — same string as
            // the static rail button (templates/map/index.html.twig), but map.js
            // (and scope-header.js's early bootstrap) need their own copy:
            // CCScope.label() (scope.js) deliberately returns null for the
            // everywhere/myArea kinds (the caller owns those strings), and the
            // header is now written straight after CCScope.init() resolves the
            // scope, before first paint (2026-07-23 flash fix) — not by reading
            // back the rendered rail button.
            'everywhereLabel' => $t->trans('region.everywhere.label'),
            // My-area header/search line (map-and-search.md §4.5
            // Phase 4). {place}/{km} filled by map.js tpl(); the _plain variant
            // is used when the base location has no place name.
            'myAreaLine' => $t->trans('map.my_area_line'),
            'myAreaLinePlain' => $t->trans('map.my_area_line_plain'),
            // Cold-start "Set my area" prompt + pan-away widen nudge
            // (map-and-search.md §4.5 Phase 4): rendered by map.js.
            // Note: the chip's own label ('map.set_my_area') is twig-rendered
            // (templates/map/index.html.twig), not read from this payload —
            // map.js never touches I18N.setMyArea.
            'myAreaSet' => $t->trans('map.my_area_set'),
            'myAreaSetAnon' => $t->trans('map.my_area_set_anon'),
            'outsideArea' => $t->trans('map.outside_area'),
            'areaDismiss' => $t->trans('map.area_dismiss'),
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
            'mlyLoading' => $t->trans('map.mly_loading'),
            'mlyNone' => $t->trans('map.mly_none'),
            'mlyZoom' => $t->trans('map.mly_zoom'),
            'd' => array_map(static fn (string $id): string => $t->trans('map.'.$id), $drawer),
        ];
    }

    /**
     * The whole catalog as one cacheable JSON payload (catalog-data-model.md
     * §9): a named interim until vector tiles. Letters key the layers; values
     * are the fixture shapes map.js has always consumed. Public data only
     * (unverified/verified rows).
     */
    #[Route('/map/catalog.json', name: 'map_catalog', methods: ['GET'])]
    public function catalog(Request $request, CatalogProvider $catalog, ClosureExpiryService $closures): Response
    {
        // A closure past the window its reporter stated must stop being served
        // (ClosureLifetime). The scheduled command is the mechanism; this is
        // the safety net, rate-limited to once an hour, because docs/TODO.md
        // records that nothing on the worker host runs the timers yet — and a
        // decay nobody runs is the same lie as no decay at all.
        //
        // Before the payload is built, so an expiry lands in the very response
        // that would otherwise have carried the stale closure.
        $closures->sweepOpportunistically();

        $json = $catalog->json();
        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        $response->setEtag(md5($json));
        $response->setPublic();
        // The body is session-independent (metric numbers, ISO dates, no user
        // data), but LocaleSubscriber's session read makes AbstractSessionListener
        // overwrite public caching with `private, must-revalidate` for anyone
        // carrying a session cookie — i.e. every logged-in rider. This header
        // tells it the caching decision here is deliberate (frontend review
        // 2026-08-09 #1).
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setMaxAge(3600);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * The ride heatmap's points, on their own endpoint.
     *
     * ~6,600 points, and the layer is Off by default. They used to ride inside
     * catalog.json, so every visitor paid their bytes on the critical path to
     * see a layer most of them never turn on — the source was already built
     * lazily, but the DOWNLOAD was not (frontend review 2026-08-09, the second
     * architectural item). The map fetches this on the first heatmap-On and
     * never again.
     *
     * Same caching discipline as catalog.json, and for the same reason: the
     * body is user-independent, so it is publicly cacheable and the
     * session listener is told not to override that.
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
     * Per-item change log (moderation-and-contribution.md §4.1): who changed
     * what, when, newest first. Public, read-only; feeds the map drawer's
     * "Recent changes" panel. Unknown/never-edited items simply have no rows:
     * 200 with an empty list, not 404, so the drawer never has to
     * special-case it.
     */
    #[Route('/map/item/{id}/history', name: 'map_item_history', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function history(int $id, Request $request, ChangeHistoryView $history): Response
    {
        $json = json_encode(['history' => $history->forItem($id)], \JSON_THROW_ON_ERROR);
        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        $response->setEtag(md5($json));
        $response->setPublic();
        // The body is session-independent (metric numbers, ISO dates, no user
        // data), but LocaleSubscriber's session read makes AbstractSessionListener
        // overwrite public caching with `private, must-revalidate` for anyone
        // carrying a session cookie — i.e. every logged-in rider. This header
        // tells it the caching decision here is deliberate (frontend review
        // 2026-08-09 #1).
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setMaxAge(60);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Best-of ranking for the map's Curated mode (route-domain.md §8): ranked
     * verified-route ids for a (season, bike, region-set?) facet. Public +
     * cacheable like catalog.json; the map flags these ids `cur` and filters
     * Curated to them. `region` accepts a CSV of ids (map-and-search.md §4.5
     * §7 Phase 4) so a My-area derived scope can rank across several regions
     * at once; a bare single id stays valid. The response body (and so the
     * ETag, which hashes it) already varies with the resolved `ids`, which
     * differ per region set, so no separate cache-key handling is needed.
     */
    #[Route('/map/best-of', name: 'map_best_of', methods: ['GET'])]
    public function bestOf(Request $request, RouteRankingService $ranking): Response
    {
        $season = Season::tryFrom((string) $request->query->get('season')) ?? Season::current(new \DateTimeImmutable());
        $bikeParam = (string) $request->query->get('bike', 'all');
        $bike = 'all' === $bikeParam ? null : BikeType::tryFrom($bikeParam);
        // CSV of region ids (map-and-search.md §4.5 Phase 4): a My-area
        // derived scope sends up to BaseAreaResolver::MAX_REGIONS ids, e.g.
        // `region=1,24,23`; a bare `region=3` stays valid (single-element
        // set). Mirrors CoverageController::scopeParams's rids idiom:
        // canonical positive-integer parts only (ctype_digit + the
        // zero-padding/overflow guard keeps an oversized numeral from
        // silently saturating to a phantom id), deduped by numeric value,
        // capped, sorted for a stable IN-list. Garbage parts are dropped, not
        // rejected — an all-garbage CSV degrades to Everywhere, same as
        // omitting the param entirely.
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

        $ids = $ranking->bestOf($season, $bike, $regionIds);
        $json = json_encode([
            'season' => $season->value,
            'bike' => null === $bike ? 'all' : $bike->value,
            'ids' => $ids,
        ], \JSON_THROW_ON_ERROR);

        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        $response->setEtag(md5($json));
        $response->setPublic();
        // The body is session-independent (metric numbers, ISO dates, no user
        // data), but LocaleSubscriber's session read makes AbstractSessionListener
        // overwrite public caching with `private, must-revalidate` for anyone
        // carrying a session cookie — i.e. every logged-in rider. This header
        // tells it the caching decision here is deliberate (frontend review
        // 2026-08-09 #1).
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setMaxAge(300);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Region spotlight polygon (map-and-search.md §4.5): the simplified DB
     * boundary the map dims around, served cacheably to retire the map's
     * Nominatim fetch (an external dependency and a Nominatim usage-policy
     * problem in production). Public + cacheable like catalog.json; unknown
     * slugs 404 so the client's `.catch` degrades gracefully (the map works
     * without a spotlight).
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
        // The body is session-independent (metric numbers, ISO dates, no user
        // data), but LocaleSubscriber's session read makes AbstractSessionListener
        // overwrite public caching with `private, must-revalidate` for anyone
        // carrying a session cookie — i.e. every logged-in rider. This header
        // tells it the caching decision here is deliberate (frontend review
        // 2026-08-09 #1).
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        // Boundaries change only on a versioned re-import (rare); an hour matches
        // catalog.json's discipline and keeps the shared cache warm.
        $response->setMaxAge(3600);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Scope-union boundary (coverage-provider.md §4):
     * the ST_Union of a scope's regions — an explicit `rids` id list and/or
     * every region of a `cc` country — so the map's dim mask can grey
     * everything outside a whole-country scope, not just a single named
     * region. `rids` parsing mirrors CoverageController::scopeParams's rids
     * idiom (canonical positive-integer CSV parts only, garbage dropped).
     * Public + cacheable like regionBoundary; an empty scope (no rids, no cc)
     * is a 204 — there is nothing to dim around.
     */
    #[Route('/map/scope/boundary', name: 'map_scope_boundary', methods: ['GET'])]
    public function scopeBoundary(Request $request, RegionBoundaryProvider $boundaries): Response
    {
        // all() never throws on an array-valued param, unlike get(), so
        // `rids[]=1` / `cc[]=BE` degrade to Everywhere instead of a 400
        // (CoverageController::scopeParams's idiom).
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
        // The body is session-independent (metric numbers, ISO dates, no user
        // data), but LocaleSubscriber's session read makes AbstractSessionListener
        // overwrite public caching with `private, must-revalidate` for anyone
        // carrying a session cookie — i.e. every logged-in rider. This header
        // tells it the caching decision here is deliberate (frontend review
        // 2026-08-09 #1).
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setMaxAge(3600);
        $response->isNotModified($request);

        return $response;
    }
}
