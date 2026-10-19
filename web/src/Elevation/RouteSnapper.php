<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Elevation;

use App\Catalog\BikeType;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Snaps two points to a road line via our Valhalla (`bicycle` costing)
 * (docs/specs/climb-elevation.md §3e). Null when unset or unreachable, never a guess.
 *
 * With a bike (docs/specs/route-planner.md section 4.2) the request carries
 * that bike's costing block, so the planner's leg follows the roads that suit
 * the bike; without one the request is exactly the climb editor's. With
 * `shortest` the engine looks for the shortest way the same bike may ride, its
 * `shortest` on top of the bike's block, for "Why this way?" (section 4.2a).
 *
 * @api
 */
final class RouteSnapper
{
    /** The most points of one track piece sent to the engine's map matching: a piece is a few kilometres. */
    public const int MAX_MATCH_POINTS = 2000;

    /** The engine's bike rules for map matching: the most permissive bike, every surface it may ride at no cost. */
    private const array MATCH_COSTING = ['bicycle_type' => 'Mountain', 'avoid_bad_surfaces' => 0.0, 'use_roads' => 0.5];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $valhallaUrl,
        private readonly ?ElevationEndpoints $endpoints = null,
    ) {
    }

    /**
     * @param array{0: float, 1: float}       $a     [lat, lng]
     * @param array{0: float, 1: float}       $b     [lat, lng]
     * @param list<array{0: float, 1: float}> $avoid [lat, lng] points on roads the leg should not take: the engine leaves
     *                                               out the edge each one lies on (route-planner.md section 4.2, a road
     *                                               another leg of the ride already takes)
     *
     * @return array{coordinates: list<array{0: float, 1: float}>, distanceM: float}|null
     *                                                                                    [lng, lat] GeoJSON; null when unset or unreachable
     */
    public function snap(array $a, array $b, ?BikeType $bike = null, bool $shortest = false, array $avoid = []): ?array
    {
        $res = $this->snapRequest($a, $b, $bike, $shortest, $avoid);

        return null === $res ? null : $this->snapResult($res);
    }

    /**
     * Many single legs at once, each with its own roads to keep off (route-planner.md section 4.2, owner 2026-10-18:
     * the repeat check asked its legs one after another). Every request goes out before the first answer is read; one
     * answer per leg, in order, each as snap() answers it.
     *
     * @param list<array{a: array{0: float, 1: float}, b: array{0: float, 1: float}, avoid: list<array{0: float, 1: float}>}> $legs [lat, lng]
     *
     * @return list<array{coordinates: list<array{0: float, 1: float}>, distanceM: float}|null>
     */
    public function snapMany(array $legs, ?BikeType $bike = null): array
    {
        $pending = array_map(fn (array $leg): ?ResponseInterface => $this->snapRequest($leg['a'], $leg['b'], $bike, false, $leg['avoid']), $legs);

        return array_map(fn (?ResponseInterface $res): ?array => null === $res ? null : $this->snapResult($res), $pending);
    }

    /**
     * Pieces of a ride file's track onto the roads, each on its own, all at once (route-planner.md section 6.1, owner
     * 2026-10-18: Liège-Bastogne-Liège opened as 50 checkpoints rode round the Stockeu instead of up it). The engine's
     * map matching (`trace_route`, `map_snap`) follows the track's own roads where routing would pick its own. A piece
     * longer than MAX_MATCH_POINTS is thinned evenly first, both ends kept. One answer per piece, in order, as snap()
     * answers it; null for a piece the engine cannot match.
     *
     * @param list<list<array{0: float, 1: float}>> $pieces [lat, lng] points per piece, two or more each
     *
     * @return list<array{coordinates: list<array{0: float, 1: float}>, distanceM: float}|null>
     */
    public function matchMany(array $pieces, ?BikeType $bike = null): array
    {
        $pending = array_map(fn (array $piece): ?ResponseInterface => $this->matchRequest($piece, $bike), $pieces);

        return array_map(fn (?ResponseInterface $res): ?array => null === $res ? null : $this->snapResult($res), $pending);
    }

    /**
     * @param list<array{0: float, 1: float}> $piece
     */
    private function matchRequest(array $piece, ?BikeType $bike): ?ResponseInterface
    {
        if ('' === $this->valhallaUrl || \count($piece) < 2) {
            return null;
        }
        $n = \count($piece);
        if ($n > self::MAX_MATCH_POINTS) {
            $step = ($n - 1) / (self::MAX_MATCH_POINTS - 1);
            $piece = array_map(static fn (int $i): array => $piece[(int) round($i * $step)], range(0, self::MAX_MATCH_POINTS - 1));
        }
        $url = $this->endpoints?->forShape([$piece[0], $piece[\count($piece) - 1]]) ?? $this->valhallaUrl;
        $json = [
            'shape' => array_map(static fn (array $p): array => ['lat' => $p[0], 'lon' => $p[1]], $piece),
            'costing' => 'bicycle',
            'shape_match' => 'map_snap',
            'directions_type' => 'none',
        ];
        // Every road a bike may ride, whatever the bike: the file says where the rider went, and a road bike's own
        // rules (no gravel, no rough road) would move a piece of it onto another road.
        $json['costing_options'] = ['bicycle' => self::MATCH_COSTING];
        try {
            return $this->http->request('POST', rtrim($url, '/').'/trace_route', ['json' => $json, 'timeout' => 15]);
        } catch (\Throwable $e) {
            $this->logger->warning('track match failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param array{0: float, 1: float}       $a
     * @param array{0: float, 1: float}       $b
     * @param list<array{0: float, 1: float}> $avoid
     */
    private function snapRequest(array $a, array $b, ?BikeType $bike, bool $shortest, array $avoid): ?ResponseInterface
    {
        if ('' === $this->valhallaUrl) {
            return null;   // same master switch as elevation: unset = no routing
        }

        $url = $this->endpoints?->forShape([$a, $b]) ?? $this->valhallaUrl;

        $json = [
            'locations' => [
                ['lat' => $a[0], 'lon' => $a[1]],
                ['lat' => $b[0], 'lon' => $b[1]],
            ],
            'costing' => 'bicycle',
            // The editor wants the line; turn-by-turn prose is dead weight.
            'directions_type' => 'none',
        ];
        if ([] !== $avoid) {
            $json['exclude_locations'] = array_map(static fn (array $p): array => ['lat' => $p[0], 'lon' => $p[1]], $avoid);
        }
        if (null !== $bike) {
            $json['costing_options'] = ['bicycle' => BikeCosting::options($bike) + ($shortest ? ['shortest' => true] : [])];
        }
        try {
            return $this->http->request('POST', rtrim($url, '/').'/route', [
                'json' => $json,
                'timeout' => 8,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('route snap failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array{coordinates: list<array{0: float, 1: float}>, distanceM: float}|null
     */
    private function snapResult(ResponseInterface $res): ?array
    {
        try {
            $data = $res->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('route snap failed', ['error' => $e->getMessage()]);

            return null;
        }

        $legs = $data['trip']['legs'] ?? null;
        if (!\is_array($legs) || [] === $legs) {
            return null;
        }

        $coordinates = [];
        $distanceKm = 0.0;
        foreach ($legs as $leg) {
            $shape = $leg['shape'] ?? null;
            if (!\is_string($shape) || '' === $shape) {
                return null;
            }
            $pts = self::decodePolyline6($shape);
            if (\count($pts) < 2) {
                return null;
            }
            // Consecutive legs repeat the join point.
            if ([] !== $coordinates) {
                array_shift($pts);
            }
            $coordinates = array_merge($coordinates, $pts);
            $distanceKm += (float) ($leg['summary']['length'] ?? 0.0);
        }

        return ['coordinates' => $coordinates, 'distanceM' => round($distanceKm * 1000.0, 1)];
    }

    /**
     * A ride's legs in one engine call (route-planner.md section 8, owner 2026-10-18): the engine answers one leg per
     * pair of consecutive stops, each the leg snap() would route alone. Null when the engine is off or does not answer,
     * or when any leg has no road: the engine then refuses the whole trip, and the planner asks leg by leg.
     *
     * @param list<array{0: float, 1: float}> $stops [lat, lng], two or more
     *
     * @return list<array{coordinates: list<array{0: float, 1: float}>, distanceM: float}>|null
     */
    public function snapEach(array $stops, ?BikeType $bike = null): ?array
    {
        if ('' === $this->valhallaUrl || \count($stops) < 2) {
            return null;
        }
        $url = $this->endpoints?->forShape($stops) ?? $this->valhallaUrl;
        $json = [
            'locations' => array_map(static fn (array $p): array => ['lat' => $p[0], 'lon' => $p[1]], $stops),
            'costing' => 'bicycle',
            'directions_type' => 'none',
        ];
        if (null !== $bike) {
            $json['costing_options'] = ['bicycle' => BikeCosting::options($bike)];
        }
        try {
            $data = $this->http->request('POST', rtrim($url, '/').'/route', ['json' => $json, 'timeout' => 12])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('ride snap failed', ['error' => $e->getMessage()]);

            return null;
        }
        $legs = $data['trip']['legs'] ?? null;
        if (!\is_array($legs) || \count($legs) !== \count($stops) - 1) {
            return null;
        }
        $out = [];
        foreach ($legs as $leg) {
            $shape = \is_array($leg) ? ($leg['shape'] ?? null) : null;
            $pts = \is_string($shape) && '' !== $shape ? self::decodePolyline6($shape) : [];
            if (\count($pts) < 2) {
                return null;
            }
            $length = \is_array($leg['summary'] ?? null) && is_numeric($leg['summary']['length'] ?? null) ? (float) $leg['summary']['length'] : 0.0;
            $out[] = ['coordinates' => $pts, 'distanceM' => round($length * 1000.0, 1)];
        }

        return $out;
    }

    /**
     * Valhalla's encoded shape: Google polyline algorithm at 1e-6 precision.
     *
     * @return list<array{0: float, 1: float}> [lng, lat] pairs
     */
    private static function decodePolyline6(string $encoded): array
    {
        $points = [];
        $index = 0;
        $len = \strlen($encoded);
        $lat = 0;
        $lng = 0;
        while ($index < $len) {
            foreach (['lat', 'lng'] as $which) {
                $result = 0;
                $shift = 0;
                do {
                    if ($index >= $len) {
                        return $points;   // truncated shape: keep what decoded cleanly
                    }
                    $byte = \ord($encoded[$index++]) - 63;
                    $result |= ($byte & 0x1F) << $shift;
                    $shift += 5;
                } while ($byte >= 0x20);
                $delta = ($result & 1) ? ~($result >> 1) : ($result >> 1);
                if ('lat' === $which) {
                    $lat += $delta;
                } else {
                    $lng += $delta;
                }
            }
            $points[] = [(float) $lng / 1e6, (float) $lat / 1e6];
        }

        return $points;
    }
}
