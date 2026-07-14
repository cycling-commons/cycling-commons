<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use App\Catalog\BikeType;
use App\Catalog\CatalogProvider;
use App\Catalog\CatalogSchemaProvider;
use App\Catalog\ChangeHistoryView;
use App\Catalog\RidingStyle;
use App\Catalog\RouteRankingService;
use App\Catalog\Season;
use App\Entity\User;
use App\Moderation\ModerationScopeProvider;
use App\Moderation\SubmissionQueue;
use App\Security\TwoFactorPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
    public function map(SubmissionQueue $queue, CatalogSchemaProvider $schema, TranslatorInterface $translator, ModerationScopeProvider $scopeProvider, TwoFactorPolicy $twoFactorPolicy): Response
    {
        $user = $this->getUser();
        $params = [
            'field_schema' => $schema->all(),
            'map_i18n' => $this->mapI18n($translator),
            // Rider preferences ride the page render (spec 2026-07-14 map
            // prefilter): value-lists only, [] for anonymous — map.js treats
            // empty as "no prefilter" so anonymous behaviour is unchanged.
            'rider_prefs' => [
                'bikes' => $user instanceof User
                    ? array_map(static fn (BikeType $t): string => $t->value, $user->getBikeTypes())
                    : [],
                'styles' => $user instanceof User
                    ? array_map(static fn (RidingStyle $s): string => $s->value, $user->getRidingStyles())
                    : [],
            ],
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
            $params['pending'] = $queue->pendingForMap($scopeProvider->scopeFor($user));
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
            'townsOnRoute' => 'd_towns_on_route', 'surfaces' => 'd_surfaces', 'submittedBy' => 'd_submitted_by',
            'age' => 'd_age', 'where' => 'd_where', 'place' => 'd_place', 'wallonia' => 'd_wallonia',
            'officialRegistry' => 'd_official_registry', 'confirmed' => 'd_confirmed', 'simulated' => 'd_simulated',
            'drinkingWater' => 'd_drinking_water', 'headlineDrinking' => 'd_headline_drinking',
            'potableOsm' => 'd_potable_osm', 'potableSim' => 'd_potable_sim', 'verifyWater' => 'd_verify_water',
            'proposedVerify' => 'd_proposed_verify', 'estimateMethod' => 'd_estimate_method',
            'contributedGpx' => 'd_contributed_gpx', 'srcAuto' => 'd_src_auto', 'srcRider' => 'd_src_rider',
            'source' => 'd_source', 'editItem' => 'd_edit_item', 'fixLocation' => 'd_fix_location',
            'voteRound' => 'd_vote_round', 'downloadGpx' => 'd_download_gpx',
            'proposedChange' => 'd_proposed_change', 'history' => 'd_history', 'initialEntry' => 'd_initial_entry',
            'recentChanges' => 'd_recent_changes', 'modNotePh' => 'd_mod_note_ph', 'approve' => 'd_approve',
            'needsInfo' => 'd_needs_info', 'reject' => 'd_reject', 'modKeys' => 'd_mod_keys',
            'decisionErr' => 'd_decision_err', 'decisionRecorded' => 'd_decision_recorded',
            'rodeThis' => 'd_rode_this', 'bikeTypePh' => 'd_bike_type_ph', 'recommend' => 'd_recommend',
            'vote' => 'd_vote', 'suggestCorrection' => 'd_suggest_correction', 'optionalDetail' => 'd_optional_detail',
            'markParts' => 'd_mark_parts', 'send' => 'd_send', 'loginRate' => 'd_login_rate',
            'ridesProgress' => 'd_rides_progress', 'voteOne' => 'd_vote_one', 'voteMany' => 'd_vote_many',
            'youRode' => 'd_you_rode', 'votedSeason' => 'd_voted_season',
            'reasonBroken' => 'd_reason_broken', 'reasonPrivacy' => 'd_reason_privacy',
            'reasonDuplicate' => 'd_reason_duplicate', 'reasonNotRideable' => 'd_reason_notrideable',
            'reasonOther' => 'd_reason_other',
            'waterQ' => 'd_water_q', 'hereQ' => 'd_here_q', 'notPotable' => 'd_not_potable',
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
            'fromGpx' => 'd_from_gpx', 'gradProfile' => 'd_grad_profile', 'illustrative' => 'd_illustrative',
            'elevAria' => 'd_elev_aria', 'sharedBy' => 'd_shared_by', 'viewProfile' => 'd_view_profile',
            'sharedAnon' => 'd_shared_anon', 'steepest' => 'd_steepest',
            'freshFresh' => 'd_fresh_fresh', 'freshAgeing' => 'd_fresh_ageing', 'freshStale' => 'd_fresh_stale',
            'lastConfirmed' => 'd_last_confirmed', 'thisSeason' => 'd_this_season',
            'city' => 'd_city', 'notesNone' => 'd_notes_none', 'nearbyH' => 'd_nearby_h', 'nothingHere' => 'd_nothing_here',
            'rideCheck' => 'd_ride_check',
            'alongRide' => 'd_along_ride', 'rideMeta' => 'd_ride_meta', 'clearRide' => 'd_clear_ride',
            'rideFollows' => 'd_ride_follows', 'kmShared' => 'd_km_shared', 'alongTrackH' => 'd_along_track_h',
            'capped' => 'd_capped', 'kmOff' => 'd_km_off', 'nothingWithin' => 'd_nothing_within',
            'noMatch' => 'd_no_match', 'places' => 'd_places',
            'suggestedRoute' => 'd_suggested_route', 'start' => 'd_start', 'shape' => 'd_shape',
            'roundtrip' => 'd_roundtrip', 'season' => 'd_season', 'why' => 'd_why', 'note' => 'd_note',
            'popularSeason' => 'd_popular_season', 'fakedNote' => 'd_faked_note', 'fakedSrc' => 'd_faked_src',
        ];

        return [
            'layers' => array_map(static fn (string $id): string => $t->trans($id), $layers),
            'deselectAll' => $t->trans('map.deselect_all'),
            'selectAll' => $t->trans('map.select_all'),
            'curated' => $t->trans('map.curated'),
            'subEverything' => $t->trans('map.sub_everything'),
            'allBikes' => $t->trans('map.all_bikes'),
            'pendingReview' => $t->trans('map.pending_review'),
            'login' => $t->trans('nav.login'),
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
     * The whole catalog as one cacheable JSON payload — spec §7's named interim
     * until vector tiles. Letters key the layers; values are the fixture shapes
     * map.js has always consumed. Public data only (unverified/verified rows).
     */
    #[Route('/map/catalog.json', name: 'map_catalog', methods: ['GET'])]
    public function catalog(Request $request, CatalogProvider $catalog): Response
    {
        $json = $catalog->json();
        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        $response->setEtag(md5($json));
        $response->setPublic();
        $response->setMaxAge(3600);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Per-item change log (design spec W5): who changed what, when — newest
     * first. Public, read-only; feeds the map drawer's "Recent changes"
     * (C1-T3). Unknown/never-edited items simply have no rows — 200 with an
     * empty list, not 404, so the drawer never has to special-case it.
     */
    #[Route('/map/item/{id}/history', name: 'map_item_history', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function history(int $id, Request $request, ChangeHistoryView $history): Response
    {
        $json = json_encode(['history' => $history->forItem($id)], \JSON_THROW_ON_ERROR);
        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        $response->setEtag(md5($json));
        $response->setPublic();
        $response->setMaxAge(60);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Best-of ranking for the map's Curated mode (spec §8): ranked verified-route
     * ids for a (season, bike, region?) facet. Public + cacheable like
     * catalog.json; the map flags these ids `cur` and filters Curated to them.
     */
    #[Route('/map/best-of', name: 'map_best_of', methods: ['GET'])]
    public function bestOf(Request $request, RouteRankingService $ranking): Response
    {
        $season = Season::tryFrom((string) $request->query->get('season')) ?? Season::current(new \DateTimeImmutable());
        $bikeParam = (string) $request->query->get('bike', 'all');
        $bike = 'all' === $bikeParam ? null : BikeType::tryFrom($bikeParam);   // invalid → null (all)
        $region = $request->query->has('region') ? $request->query->getInt('region') : null;

        $ids = $ranking->bestOf($season, $bike, $region);
        $json = json_encode([
            'season' => $season->value,
            'bike' => null === $bike ? 'all' : $bike->value,
            'ids' => $ids,
        ], \JSON_THROW_ON_ERROR);

        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        $response->setEtag(md5($json));
        $response->setPublic();
        $response->setMaxAge(300);
        $response->isNotModified($request);

        return $response;
    }
}
