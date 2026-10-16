<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\BikeType;
use App\Catalog\RideCheckService;
use App\Contribution\Gpx\TrackProcessor;
use App\Elevation\ElevationClient;
use App\Elevation\LegSurfaces;
use App\Elevation\RouteSnapper;
use App\Entity\User;
use App\Security\PseudonymousKey;
use App\Weather\Forecast;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
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

        if (!$this->spend($request, $planLegLimiter, $planLegAnonLimiter, $secret)) {
            return new JsonResponse(['error' => 'rate_limited'], 429);
        }

        // `shortest: true` is the comparison leg of "Why this way?" (route-planner.md section 4.2a).
        $snapped = $snapper->snap($points['a'], $points['b'], $bike, true === ($payload['shortest'] ?? null));
        if (null === $snapped) {
            // No road is an answer, not an error, and it carries no line: nothing that is not a road leaves this server.
            return new JsonResponse(['routed' => false]);
        }
        $latLng = array_map(static fn (array $c): array => [$c[1], $c[0]], $snapped['coordinates']);
        $heights = $elevation->profile($latLng);
        // The surfaces and the street names under the leg, from one walk (section 4.4a); null leaves them out.
        $read = $surfaces->read($latLng);

        return new JsonResponse([
            'routed' => true,
            'coordinates' => $snapped['coordinates'],
            'distanceM' => $snapped['distanceM'],
            'heights' => $heights,
            'surfaces' => $read['surfaces'] ?? null,
            'streets' => $read['streets'] ?? null,
        ]);
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
        return new JsonResponse([...$rideCheck->alongLine($geoJson, $lengthM, RideCheckService::DEFAULT_RADIUS), 'regions' => $rideCheck->crossedRegions($geoJson)]);
    }

    /**
     * The forecast for a planned ride's date (docs/specs/route-planner.md
     * section 4.4): one point, rounded before it leaves our server, and one
     * date; `forecast: null` when the service is off, does not answer, or the
     * date is past its reach, so the panel simply shows no line.
     */
    #[Route('/map/plan/weather', name: 'map_plan_weather', methods: ['POST'])]
    public function weather(
        Request $request,
        Forecast $forecast,
        RateLimiterFactoryInterface $planWeatherLimiter,
        RateLimiterFactoryInterface $planWeatherAnonLimiter,
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
        if (!$this->spend($request, $planWeatherLimiter, $planWeatherAnonLimiter, $secret)) {
            return new JsonResponse(['error' => 'rate_limited'], 429);
        }

        return new JsonResponse(['forecast' => $forecast->forDay((float) $lat, (float) $lng, $date)]);
    }

    /** A rider spends their own budget; a visitor spends the address-hash budget. */
    private function spend(Request $request, RateLimiterFactoryInterface $riders, RateLimiterFactoryInterface $visitors, string $secret): bool
    {
        $user = $this->getUser();
        if ($user instanceof User) {
            return $riders->create('user-'.(string) $user->getId())->consume()->isAccepted();
        }

        return $visitors->create(self::anonKey($request->getClientIp() ?? 'unknown', $secret))->consume()->isAccepted();
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
