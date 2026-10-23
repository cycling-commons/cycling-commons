<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\BikeType;
use App\Catalog\RideCheckService;
use App\Contribution\Gpx\TrackProcessor;
use App\Elevation\ElevationClient;
use App\Elevation\LegSurfaces;
use App\Elevation\PointSnapper;
use App\Elevation\RouteSnapper;
use App\Entity\User;
use App\Security\PseudonymousKey;
use App\Weather\Forecast;
use App\Weather\ForecastUsage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The ride planner's two server calls (docs/specs/route-planner.md section 8).
 *
 * `leg` routes one leg between two stops for one bike through our own
 * routing engine and reads the leg's heights; `along` lists what the Commons
 * has along the planned line, with the ride check's corridor. Both follow the
 * stateless-JSON pattern (security-architecture.md section 5.1): a stateless
 * `X-CC-Token`, cheap validation before any budget is spent, a per-user
 * limiter for a rider and, for a visitor, a limiter keyed on a salted hash of
 * the address, never the address. The browser never talks to the engine.
 *
 * @api
 */
final class PlanController extends AbstractController
{
    /** The most stops one /map/plan/legs request routes: the planner's own cap (plan-model.js MAX_STOPS). */
    public const int MAX_RIDE_STOPS = 50;

    /** The most points a leg may be asked to keep off (route-planner.md section 4.2): the engine's own cap is 50. */
    public const int MAX_AVOID = 40;

    /** How near its checkpoints a matched piece must start and end, metres, to stand for the piece. */
    public const float MATCH_END_M = 50.0;

    /** The most track points one /map/plan/match request carries: a long day's file, thinned in the browser to ~20 m. */
    public const int MAX_MATCH_TOTAL = 30000;

    /** How far from the line `along` may look, metres (route-planner.md section 5): 250 m unless the rider picks more. */
    public const array ALONG_RADII = [250, 500, 1000, 2000, 5000];

    /** The most vertices a planned line may carry to `along`; the planner's line is the engine's, dense but bounded by the stop cap. */
    public const int MAX_LINE_POINTS = 5000;

    /** Salted address hash for the anonymous limiters: volume, not identity (the ride check's construction). */
    public static function anonKey(string $ip, string $secret): string
    {
        return PseudonymousKey::limiter('plan', $ip, $secret);
    }

    #[Route('/map/plan/leg', name: 'map_plan_leg', methods: ['POST'])]
    public function leg(
        Request $request,
        RouteSnapper $snapper,
        ElevationClient $elevation,
        LegSurfaces $surfaces,
        RateLimiterFactoryInterface $planLegLimiter,
        RateLimiterFactoryInterface $planLegAnonLimiter,
        #[Autowire('%kernel.secret%')]
        string $secret,
    ): JsonResponse {
        // A plain 403 for everyone: the security component's AccessDenied would
        // send a visitor to the login page as a 302, which a fetch() reads as a
        // broken body, and this endpoint is open to visitors.
        if (!$this->isCsrfTokenValid('plan-leg', (string) $request->headers->get('X-CC-Token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];

        $points = [];
        foreach (['a', 'b'] as $key) {
            $point = self::point($payload[$key] ?? null);
            if (null === $point) {
                return new JsonResponse(['error' => 'bad_request'], 400);
            }
            $points[$key] = $point;
        }
        // An unknown bike is refused here: the engine would silently ride its
        // default and move the route (route-planner.md section 4.2).
        $bike = BikeType::tryFrom(\is_string($payload['bike'] ?? null) ? $payload['bike'] : '');
        if (null === $bike) {
            return new JsonResponse(['error' => 'unknown_bike'], 400);
        }

        $budget = $this->limit($request, $planLegLimiter, $planLegAnonLimiter, $secret);
        if (!$budget->isAccepted()) {
            return $this->legsSpent($budget);
        }

        // `avoid`: points on roads another leg of the ride already takes, kept off where there is another way (section 4.2).
        $avoid = self::avoid($payload['avoid'] ?? []);
        if (null === $avoid) {
            return new JsonResponse(['error' => 'bad_request'], 400);
        }
        // `shortest: true` is the comparison leg of "Why this way?" (route-planner.md section 4.2a).
        $snapped = $snapper->snap($points['a'], $points['b'], $bike, true === ($payload['shortest'] ?? null), $avoid);
        if (null === $snapped) {
            // No road is an answer, not an error, and it carries no line: nothing that is not a road leaves this server.
            return new JsonResponse(['routed' => false, 'budget' => $this->budget($budget)]);
        }
        $latLng = array_map(static fn (array $c): array => [$c[1], $c[0]], $snapped['coordinates']);

        // The surfaces and the street names under the leg, from one walk (section 4.4a); null leaves them out.
        return new JsonResponse([...self::legJson($snapped, $elevation->profile($latLng), $surfaces->read($latLng)), 'budget' => $this->budget($budget)]);
    }

    /**
     * A ride's legs in one request (route-planner.md section 8, owner 2026-10-18: reopening a ride of twenty legs
     * took twenty requests one after another). One engine call routes every pair of consecutive stops; the heights
     * and the surfaces of all legs are then asked at once. Each leg is the answer /map/plan/leg gives for it. `legs:
     * null` when the engine routes the ride not whole (a leg without a road): the planner then asks leg by leg.
     * Every leg counts against the same limiter as a single leg.
     */
    #[Route('/map/plan/legs', name: 'map_plan_legs', methods: ['POST'])]
    public function legs(
        Request $request,
        RouteSnapper $snapper,
        ElevationClient $elevation,
        LegSurfaces $surfaces,
        RateLimiterFactoryInterface $planLegLimiter,
        RateLimiterFactoryInterface $planLegAnonLimiter,
        #[Autowire('%kernel.secret%')]
        string $secret,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('plan-leg', (string) $request->headers->get('X-CC-Token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];
        $raw = $payload['stops'] ?? null;
        if (!\is_array($raw) || \count($raw) < 2 || \count($raw) > self::MAX_RIDE_STOPS) {
            return new JsonResponse(['error' => 'bad_request'], 400);
        }
        $stops = [];
        foreach ($raw as $p) {
            $point = self::point($p);
            if (null === $point) {
                return new JsonResponse(['error' => 'bad_request'], 400);
            }
            $stops[] = $point;
        }
        $bike = BikeType::tryFrom(\is_string($payload['bike'] ?? null) ? $payload['bike'] : '');
        if (null === $bike) {
            return new JsonResponse(['error' => 'unknown_bike'], 400);
        }
        $budget = $this->limit($request, $planLegLimiter, $planLegAnonLimiter, $secret, \count($stops) - 1);
        if (!$budget->isAccepted()) {
            return $this->legsSpent($budget);
        }
        $snapped = $snapper->snapEach($stops, $bike);
        if (null === $snapped) {
            return new JsonResponse(['legs' => null, 'budget' => $this->budget($budget)]);
        }
        $lines = array_map(static fn (array $leg): array => array_map(static fn (array $c): array => [$c[1], $c[0]], $leg['coordinates']), $snapped);
        $heights = $elevation->profileMany($lines);
        $reads = $surfaces->readMany($lines);
        $out = [];
        foreach ($snapped as $i => $leg) {
            $out[] = self::legJson($leg, $heights[$i] ?? null, $reads[$i] ?? null);
        }

        return new JsonResponse(['legs' => $out, 'budget' => $this->budget($budget)]);
    }

    /**
     * The repeat check's legs in one request (route-planner.md section 4.2, owner 2026-10-18: a reopened ride asked
     * every leg that rides an earlier leg's road again, one after another). Each leg is asked alone with its own roads
     * to keep off, all of them at once; one answer per leg, in order, as /map/plan/leg answers it. Every leg counts
     * against the same limiter as a single leg.
     */
    #[Route('/map/plan/detours', name: 'map_plan_detours', methods: ['POST'])]
    public function detours(
        Request $request,
        RouteSnapper $snapper,
        ElevationClient $elevation,
        LegSurfaces $surfaces,
        RateLimiterFactoryInterface $planLegLimiter,
        RateLimiterFactoryInterface $planLegAnonLimiter,
        #[Autowire('%kernel.secret%')]
        string $secret,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('plan-leg', (string) $request->headers->get('X-CC-Token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];
        $raw = $payload['legs'] ?? null;
        if (!\is_array($raw) || [] === $raw || \count($raw) > self::MAX_RIDE_STOPS - 1) {
            return new JsonResponse(['error' => 'bad_request'], 400);
        }
        $asks = [];
        foreach ($raw as $leg) {
            $a = \is_array($leg) ? self::point($leg['a'] ?? null) : null;
            $b = \is_array($leg) ? self::point($leg['b'] ?? null) : null;
            $avoid = \is_array($leg) ? self::avoid($leg['avoid'] ?? []) : null;
            if (null === $a || null === $b || null === $avoid) {
                return new JsonResponse(['error' => 'bad_request'], 400);
            }
            $asks[] = ['a' => $a, 'b' => $b, 'avoid' => $avoid];
        }
        $bike = BikeType::tryFrom(\is_string($payload['bike'] ?? null) ? $payload['bike'] : '');
        if (null === $bike) {
            return new JsonResponse(['error' => 'unknown_bike'], 400);
        }
        $budget = $this->limit($request, $planLegLimiter, $planLegAnonLimiter, $secret, \count($asks));
        if (!$budget->isAccepted()) {
            return $this->legsSpent($budget);
        }
        $snapped = array_filter($snapper->snapMany($asks, $bike));
        $lines = array_map(static fn (array $leg): array => array_map(static fn (array $c): array => [$c[1], $c[0]], $leg['coordinates']), $snapped);
        $heights = array_combine(array_keys($lines), $elevation->profileMany(array_values($lines)));
        $reads = array_combine(array_keys($lines), $surfaces->readMany(array_values($lines)));
        $out = [];
        foreach (array_keys($asks) as $i) {
            // No road is an answer that carries no line, as for a single leg.
            $out[] = isset($snapped[$i]) ? self::legJson($snapped[$i], $heights[$i] ?? null, $reads[$i] ?? null) : ['routed' => false];
        }

        return new JsonResponse(['legs' => $out, 'budget' => $this->budget($budget)]);
    }

    /**
     * A ride file's legs on the file's own roads (route-planner.md section 6.1, owner 2026-10-18: Liège-Bastogne-Liège
     * opened as 50 checkpoints rode round the Stockeu instead of up it). `legs` holds the file's track cut at the
     * checkpoints, one piece per leg; each piece is matched to the roads on its own, all at once, and gets its heights
     * and surfaces as a routed leg does. One answer per piece, in order, as /map/plan/leg answers it, with `fromFile`
     * and `matched`: false when the piece's own line stands in for a match that fell short. The track passes
     * through; nothing of it is kept. Every piece counts against the legs' limiter as one leg.
     */
    #[Route('/map/plan/match', name: 'map_plan_match', methods: ['POST'])]
    public function match(
        Request $request,
        RouteSnapper $snapper,
        ElevationClient $elevation,
        LegSurfaces $surfaces,
        RateLimiterFactoryInterface $planLegLimiter,
        RateLimiterFactoryInterface $planLegAnonLimiter,
        #[Autowire('%kernel.secret%')]
        string $secret,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('plan-leg', (string) $request->headers->get('X-CC-Token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];
        $raw = $payload['legs'] ?? null;
        if (!\is_array($raw) || [] === $raw || \count($raw) > self::MAX_RIDE_STOPS - 1) {
            return new JsonResponse(['error' => 'bad_request'], 400);
        }
        $pieces = [];
        $total = 0;
        foreach ($raw as $piece) {
            if (!\is_array($piece) || \count($piece) < 2 || \count($piece) > RouteSnapper::MAX_MATCH_POINTS) {
                return new JsonResponse(['error' => 'bad_request'], 400);
            }
            $total += \count($piece);
            $points = [];
            foreach ($piece as $p) {
                $point = self::point($p);
                if (null === $point) {
                    return new JsonResponse(['error' => 'bad_request'], 400);
                }
                $points[] = $point;
            }
            $pieces[] = $points;
        }
        if ($total > self::MAX_MATCH_TOTAL) {
            return new JsonResponse(['error' => 'bad_request'], 400);
        }
        $bike = BikeType::tryFrom(\is_string($payload['bike'] ?? null) ? $payload['bike'] : '');
        if (null === $bike) {
            return new JsonResponse(['error' => 'unknown_bike'], 400);
        }
        $budget = $this->limit($request, $planLegLimiter, $planLegAnonLimiter, $secret, \count($pieces));
        if (!$budget->isAccepted()) {
            return $this->legsSpent($budget);
        }
        // A match that stops short of the next checkpoint, or strays far from the piece's length (a one-way street the
        // ride took the other way, a road missing from the map), gives way to the file's own line: the file is where
        // the rider went (owner 2026-10-18: a 7.2 km leg near Houffalize matched 0.8 km).
        $legs = [];
        foreach ($snapper->matchMany($pieces) as $i => $m) {
            $line = array_map(static fn (array $p): array => [$p[1], $p[0]], $pieces[$i]);
            $lengthM = self::lengthM($pieces[$i]);
            $whole = null !== $m && \count($m['coordinates']) > 1
                && self::metresBetween($m['coordinates'][0], $line[0]) <= self::MATCH_END_M
                && self::metresBetween($m['coordinates'][\count($m['coordinates']) - 1], $line[\count($line) - 1]) <= self::MATCH_END_M
                && $m['distanceM'] >= $lengthM * 0.85 && $m['distanceM'] <= $lengthM * 1.3;
            $legs[] = $whole ? [...$m, 'matched' => true] : ['coordinates' => $line, 'distanceM' => round($lengthM, 1), 'matched' => false];
        }
        $lines = array_map(static fn (array $leg): array => array_map(static fn (array $c): array => [$c[1], $c[0]], $leg['coordinates']), $legs);
        $heights = $elevation->profileMany($lines);
        $reads = $surfaces->readMany($lines);
        $out = [];
        foreach ($legs as $i => $leg) {
            $out[] = [...self::legJson(['coordinates' => $leg['coordinates'], 'distanceM' => $leg['distanceM']], $heights[$i] ?? null, $reads[$i] ?? null), 'fromFile' => true, 'matched' => $leg['matched']];
        }

        return new JsonResponse(['legs' => $out, 'budget' => $this->budget($budget)]);
    }

    /**
     * @param list<array{0: float, 1: float}> $points [lat, lng]
     */
    private static function lengthM(array $points): float
    {
        $m = 0.0;
        for ($i = 1, $n = \count($points); $i < $n; ++$i) {
            $m += self::metresBetween([$points[$i - 1][1], $points[$i - 1][0]], [$points[$i][1], $points[$i][0]]);
        }

        return $m;
    }

    /**
     * Great-circle metres between two [lng, lat] points.
     *
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     */
    private static function metresBetween(array $a, array $b): float
    {
        $r = M_PI / 180.0;
        $x = sin(($b[1] - $a[1]) * $r / 2) ** 2 + cos($a[1] * $r) * cos($b[1] * $r) * sin(($b[0] - $a[0]) * $r / 2) ** 2;

        return 2 * 6371000.0 * asin(min(1.0, sqrt($x)));
    }

    /**
     * Points on roads to keep off, `[lng, lat]` each, at most MAX_AVOID; null when the list is not that.
     *
     * @return list<array{0: float, 1: float}>|null [lat, lng]
     */
    private static function avoid(mixed $raw): ?array
    {
        if (!\is_array($raw) || \count($raw) > self::MAX_AVOID) {
            return null;
        }
        $avoid = [];
        foreach ($raw as $p) {
            $point = self::point($p);
            if (null === $point) {
                return null;
            }
            $avoid[] = $point;
        }

        return $avoid;
    }

    /**
     * The legs left to plan (route-planner.md section 8, owner 2026-10-18: "where can I see my number of legs
     * remaining?"), asked when the planner opens: nothing is spent. Every leg answer carries the same `budget`.
     */
    #[Route('/map/plan/budget', name: 'map_plan_budget', methods: ['POST'])]
    public function legsLeft(
        Request $request,
        RateLimiterFactoryInterface $planLegLimiter,
        RateLimiterFactoryInterface $planLegAnonLimiter,
        #[Autowire('%kernel.secret%')]
        string $secret,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('plan-leg', (string) $request->headers->get('X-CC-Token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        return new JsonResponse(['budget' => $this->budget($this->limit($request, $planLegLimiter, $planLegAnonLimiter, $secret, 0))]);
    }

    /**
     * The legs' budget as the planner shows it: how many are left, of how many, and over what: a visitor's `day`, a
     * rider's `window` of ten minutes.
     *
     * @return array{left: int, limit: int, per: string}
     */
    private function budget(RateLimit $limit): array
    {
        return ['left' => max(0, $limit->getRemainingTokens()), 'limit' => $limit->getLimit(), 'per' => $this->getUser() instanceof User ? 'window' : 'day'];
    }

    /**
     * The legs' budget is spent. `until` tells the planner which: a visitor's budget is a day's, a rider's ten
     * minutes', so the planner says "tomorrow" or "in a few minutes" (route-planner.md section 8).
     */
    private function legsSpent(RateLimit $limit): JsonResponse
    {
        return new JsonResponse(['error' => 'rate_limited', 'until' => $this->getUser() instanceof User ? 'minutes' : 'tomorrow', 'budget' => $this->budget($limit)], 429);
    }

    /**
     * One routed leg as the planner reads it.
     *
     * @param array{coordinates: list<array{0: float, 1: float}>, distanceM: float} $snapped
     * @param list<array{0: float, 1: float}>|null                                  $heights
     * @param array<string, mixed>|null                                             $read
     *
     * @return array<string, mixed>
     */
    private static function legJson(array $snapped, ?array $heights, ?array $read): array
    {
        return [
            'routed' => true,
            'coordinates' => $snapped['coordinates'],
            'distanceM' => $snapped['distanceM'],
            'heights' => $heights,
            'surfaces' => $read['surfaces'] ?? null,
            'streets' => $read['streets'] ?? null,
            'runs' => $read['runs'] ?? null,
            'busyRuns' => $read['busyRuns'] ?? null,
            'recorded' => $read['recorded'] ?? false,
            'quality' => $read['quality'] ?? null,
            'speeds' => $read['speeds'] ?? null,
            'detailRuns' => $read['detailRuns'] ?? null,
        ];
    }

    /**
     * A map point on the road a bike would take there (route-planner.md section 4.1): the
     * nearest edge the bike may ride that is no one's driveway, within 500 m. `snapped:
     * false` leaves the point as the rider put it. The point passes; nothing is kept.
     */
    #[Route('/map/plan/snap', name: 'map_plan_snap', methods: ['POST'])]
    public function snap(
        Request $request,
        PointSnapper $snapper,
        RateLimiterFactoryInterface $planSnapLimiter,
        RateLimiterFactoryInterface $planSnapAnonLimiter,
        #[Autowire('%kernel.secret%')]
        string $secret,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('plan-leg', (string) $request->headers->get('X-CC-Token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];
        $point = self::point($payload['point'] ?? null);
        $bike = BikeType::tryFrom(\is_string($payload['bike'] ?? null) ? $payload['bike'] : '');
        if (null === $point || null === $bike) {
            return new JsonResponse(['error' => 'bad_request'], 400);
        }
        if (!$this->spend($request, $planSnapLimiter, $planSnapAnonLimiter, $secret)) {
            return new JsonResponse(['error' => 'rate_limited'], 429);
        }
        $at = $snapper->snap($point[0], $point[1], $bike);

        return new JsonResponse(null === $at ? ['snapped' => false] : ['snapped' => true, 'point' => [$at['lng'], $at['lat']], 'distanceM' => $at['distanceM']]);
    }

    #[Route('/map/plan/along', name: 'map_plan_along', methods: ['POST'])]
    public function along(
        Request $request,
        RideCheckService $rideCheck,
        TrackProcessor $tracks,
        RateLimiterFactoryInterface $planAlongLimiter,
        RateLimiterFactoryInterface $planAlongAnonLimiter,
        #[Autowire('%kernel.secret%')]
        string $secret,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('plan-along', (string) $request->headers->get('X-CC-Token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $payload = json_decode($request->getContent(), true);
        $line = \is_array($payload) ? ($payload['line'] ?? null) : null;
        if (!\is_array($line) || 'LineString' !== ($line['type'] ?? null) || !\is_array($line['coordinates'] ?? null)) {
            return new JsonResponse(['error' => 'bad_request'], 400);
        }
        $radius = $payload['radius'] ?? RideCheckService::DEFAULT_RADIUS;
        if (!\is_int($radius) || !\in_array($radius, self::ALONG_RADII, true)) {
            return new JsonResponse(['error' => 'bad_request'], 400);
        }
        $raw = $line['coordinates'];
        if (\count($raw) < 2 || \count($raw) > self::MAX_LINE_POINTS) {
            return new JsonResponse(['error' => 'bad_request'], 400);
        }
        $coords = [];
        $track = [];
        foreach ($raw as $pair) {
            $point = self::point($pair);
            if (null === $point) {
                return new JsonResponse(['error' => 'bad_request'], 400);
            }
            $coords[] = [$point[1], $point[0]];
            $track[] = [$point[0], $point[1], null];
        }
        // The ride check's bounds: shorter is not a ride, longer is past the route-domain cap.
        $lengthM = $tracks->distanceM($track);
        if ($lengthM < RideCheckService::MIN_RAW_M || $lengthM > RideCheckService::MAX_RAW_M) {
            return new JsonResponse(['error' => 'length_range'], 400);
        }

        if (!$this->spend($request, $planAlongLimiter, $planAlongAnonLimiter, $secret)) {
            return new JsonResponse(['error' => 'rate_limited'], 429);
        }

        $geoJson = json_encode(
            ['type' => 'LineString', 'coordinates' => $coords],
            \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION,
        );

        // The regions the line crosses: the planner widens the map's scope to a ride outside it (route-planner.md section 5).
        return new JsonResponse([...$rideCheck->alongLine($geoJson, $lengthM, $radius), 'regions' => $rideCheck->crossedRegions($geoJson)]);
    }

    /**
     * The forecast for a planned ride's date (docs/specs/route-planner.md
     * section 4.4): one point, rounded before it leaves our server, and one
     * date; `forecast: null` when the service is off, does not answer, or the
     * date is past its reach, so the panel simply shows no line. With
     * `cached: true` only our cache answers, on its own limiters, and
     * `uncached: true` says an ask without it would get the forecast.
     */
    #[Route('/map/plan/weather', name: 'map_plan_weather', methods: ['POST'])]
    public function weather(
        Request $request,
        Forecast $forecast,
        ForecastUsage $usage,
        RateLimiterFactoryInterface $planWeatherLimiter,
        RateLimiterFactoryInterface $planWeatherAnonLimiter,
        RateLimiterFactoryInterface $planWeatherCachedLimiter,
        RateLimiterFactoryInterface $planWeatherCachedAnonLimiter,
        #[Autowire('%kernel.secret%')]
        string $secret,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('plan-weather', (string) $request->headers->get('X-CC-Token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $payload = json_decode($request->getContent(), true);
        $lat = \is_array($payload) ? ($payload['lat'] ?? null) : null;
        $lng = \is_array($payload) ? ($payload['lng'] ?? null) : null;
        $date = \is_array($payload) ? ($payload['date'] ?? null) : null;
        if (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180
            || !\is_string($date) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || false === \DateTimeImmutable::createFromFormat('!Y-m-d', $date)) {
            return new JsonResponse(['error' => 'bad_request'], 400);
        }
        $cachedOnly = true === ($payload['cached'] ?? false);
        if ($cachedOnly) {
            if (!$this->spend($request, $planWeatherCachedLimiter, $planWeatherCachedAnonLimiter, $secret)) {
                return new JsonResponse(['error' => 'rate_limited'], 429);
            }
            $day = $forecast->forDay((float) $lat, (float) $lng, $date, cachedOnly: true);

            return new JsonResponse(null === $day && $forecast->answers($date) ? ['forecast' => null, 'uncached' => true] : ['forecast' => $day]);
        }
        if (!$this->spend($request, $planWeatherLimiter, $planWeatherAnonLimiter, $secret)) {
            // Counted for the admin's Weather usage page (route-planner.md section 8).
            $usage->limited();

            return new JsonResponse(['error' => 'rate_limited'], 429);
        }

        return new JsonResponse(['forecast' => $forecast->forDay((float) $lat, (float) $lng, $date)]);
    }

    /** A rider spends their own budget; a visitor spends the address-hash budget. */
    private function spend(Request $request, RateLimiterFactoryInterface $riders, RateLimiterFactoryInterface $visitors, string $secret, int $tokens = 1): bool
    {
        return $this->limit($request, $riders, $visitors, $secret, $tokens)->isAccepted();
    }

    /** Spend `tokens` of the rider's or the visitor's budget and say what is left; 0 tokens only reads it. */
    private function limit(Request $request, RateLimiterFactoryInterface $riders, RateLimiterFactoryInterface $visitors, string $secret, int $tokens = 1): RateLimit
    {
        $user = $this->getUser();
        if ($user instanceof User) {
            return $riders->create('user-'.(string) $user->getId())->consume($tokens);
        }

        return $visitors->create(self::anonKey($request->getClientIp() ?? 'unknown', $secret))->consume($tokens);
    }

    /**
     * One GeoJSON point, [lng, lat], as /contribute/route takes its points.
     *
     * @return array{0: float, 1: float}|null [lat, lng], or null when it is not a point on Earth
     */
    private static function point(mixed $raw): ?array
    {
        if (!\is_array($raw) || !isset($raw[0], $raw[1]) || !is_numeric($raw[0]) || !is_numeric($raw[1])) {
            return null;
        }
        $lng = (float) $raw[0];
        $lat = (float) $raw[1];
        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return null;
        }

        return [$lat, $lng];
    }
}
