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

    /**
     * The drawing editors' rules (follow()): the line follows the road as it lies, so no bike rule bends it. A road
     * closed to bikes, a one-way street the other way, a turn ban: all drawn as they are, and the shortest way wins.
     */
    private const array FOLLOW_COSTING = ['shortest' => true, 'ignore_access' => true, 'ignore_oneways' => true, 'ignore_restrictions' => true];

    /** How often follow() steers the line back onto the tapped street before it takes the line it has. */
    public const int FOLLOW_ROUNDS = 4;

    /** A stretch off the tapped street shorter than this is a junction's crossing, not a detour: it is not steered away from. */
    private const float FOLLOW_OFF_MIN_KM = 0.03;

    /** How far from a tap a road still counts as the one tapped. */
    private const float FOLLOW_TAP_M = 15.0;

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
     * The line between two taps of a drawing editor (the road surface and climb editors, /contribute/route), following
     * the road as it lies (owner 2026-10-27: "for this street work it is best to just follow along, do not be smart").
     * No bike rule applies (FOLLOW_COSTING): the Blokdijk at the N506 is closed to bikes, and a bike route went round
     * it. And where both taps lie on one street, the line stays on that street: a stretch of the line on another road
     * is left out and the engine asked again, at most FOLLOW_ROUNDS times. The Blokdijk is 1137 m between the taps,
     * the cycle path beside it 836 m, so the shortest way alone took the cycle path. A street the engine cannot keep
     * to gives the first line, as snap() would.
     *
     * @param array{0: float, 1: float} $a [lat, lng]
     * @param array{0: float, 1: float} $b [lat, lng]
     *
     * @return array{coordinates: list<array{0: float, 1: float}>, distanceM: float}|null [lng, lat] GeoJSON
     */
    public function follow(array $a, array $b): ?array
    {
        if ('' === $this->valhallaUrl) {
            return null;
        }
        $url = $this->endpoints?->forShape([$a, $b]) ?? $this->valhallaUrl;
        $street = $this->sharedStreet($url, $a, $b);

        $first = null;
        $avoid = [];
        for ($round = 0; $round <= self::FOLLOW_ROUNDS; ++$round) {
            $trip = $this->followRequest($url, $a, $b, $avoid);
            if (null === $trip) {
                break;
            }
            $first ??= $trip['line'];
            if ([] === $street) {
                return $trip['line'];
            }
            $off = self::offStreet($trip, $street);
            if ([] === $off) {
                return $trip['line'];
            }
            $avoid = array_merge($avoid, $off);
        }

        return $first;
    }

    /**
     * The street names both taps lie on, as the engine names its edges: empty when they lie on no street in common.
     *
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     *
     * @return list<string>
     */
    private function sharedStreet(string $url, array $a, array $b): array
    {
        try {
            $data = $this->http->request('POST', rtrim($url, '/').'/locate', [
                'json' => [
                    'locations' => [['lat' => $a[0], 'lon' => $a[1]], ['lat' => $b[0], 'lon' => $b[1]]],
                    'costing' => 'bicycle',
                    'costing_options' => ['bicycle' => self::FOLLOW_COSTING],
                    'verbose' => true,
                ],
                'timeout' => 8,
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('route follow: locate failed', ['error' => $e->getMessage()]);

            return [];
        }
        $names = [];
        foreach ([0, 1] as $i) {
            $names[$i] = [];
            $edges = $data[$i]['edges'] ?? null;
            foreach (\is_array($edges) ? $edges : [] as $edge) {
                if (!\is_array($edge) || (float) ($edge['distance'] ?? \INF) > self::FOLLOW_TAP_M) {
                    continue;
                }
                foreach ((array) ($edge['edge_info']['names'] ?? []) as $name) {
                    if (\is_string($name) && '' !== $name) {
                        $names[$i][$name] = true;
                    }
                }
            }
        }

        return array_map(strval(...), array_keys(array_intersect_key($names[0], $names[1])));
    }

    /**
     * One line between the taps under FOLLOW_COSTING, keeping off the roads at `$avoid`, with the street names of its
     * stretches.
     *
     * @param array{0: float, 1: float}       $a
     * @param array{0: float, 1: float}       $b
     * @param list<array{0: float, 1: float}> $avoid [lat, lng]
     *
     * @return array{line: array{coordinates: list<array{0: float, 1: float}>, distanceM: float}, shape: list<array{0: float, 1: float}>, maneuvers: list<array<string, mixed>>}|null
     */
    private function followRequest(string $url, array $a, array $b, array $avoid): ?array
    {
        $json = [
            'locations' => [['lat' => $a[0], 'lon' => $a[1]], ['lat' => $b[0], 'lon' => $b[1]]],
            'costing' => 'bicycle',
            'costing_options' => ['bicycle' => self::FOLLOW_COSTING],
            // Each stretch with its street names; no prose.
            'directions_type' => 'maneuvers',
        ];
        if ([] !== $avoid) {
            $json['exclude_locations'] = array_map(static fn (array $p): array => ['lat' => $p[0], 'lon' => $p[1]], $avoid);
        }
        try {
            $data = $this->http->request('POST', rtrim($url, '/').'/route', ['json' => $json, 'timeout' => 8])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('route follow failed', ['error' => $e->getMessage()]);

            return null;
        }
        $leg = $data['trip']['legs'][0] ?? null;
        $shape = \is_array($leg) ? ($leg['shape'] ?? null) : null;
        if (!\is_string($shape) || '' === $shape) {
            return null;
        }
        $pts = self::decodePolyline6($shape);
        if (\count($pts) < 2) {
            return null;
        }
        $maneuvers = \is_array($leg['maneuvers'] ?? null) ? array_values(array_filter($leg['maneuvers'], \is_array(...))) : [];

        return [
            'line' => ['coordinates' => $pts, 'distanceM' => round((float) ($leg['summary']['length'] ?? 0.0) * 1000.0, 1)],
            'shape' => $pts,
            'maneuvers' => $maneuvers,
        ];
    }

    /**
     * A point in the middle of every stretch of the line that leaves the tapped street, [lat, lng]: the roads to keep
     * off next round. A stretch shorter than FOLLOW_OFF_MIN_KM is a junction's crossing and stays.
     *
     * @param array{line: array{coordinates: list<array{0: float, 1: float}>, distanceM: float}, shape: list<array{0: float, 1: float}>, maneuvers: list<array<string, mixed>>} $trip
     * @param list<string>                                                                                                                                                      $street
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function offStreet(array $trip, array $street): array
    {
        $off = [];
        foreach ($trip['maneuvers'] as $m) {
            if ((float) ($m['length'] ?? 0.0) < self::FOLLOW_OFF_MIN_KM) {
                continue;
            }
            $names = array_filter((array) ($m['street_names'] ?? []), \is_string(...));
            if ([] !== array_intersect($names, $street)) {
                continue;
            }
            $from = (int) ($m['begin_shape_index'] ?? 0);
            $to = (int) ($m['end_shape_index'] ?? $from);
            $mid = $trip['shape'][intdiv($from + $to, 2)] ?? null;
            if (null !== $mid) {
                $off[] = [$mid[1], $mid[0]];   // the shape is [lng, lat]
            }
        }

        return $off;
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
     * Legs through points on the way, all at once (the gravel hints, route-planner.md section 4.2b): each ask is a
     * leg from `a` to `b` that passes through `vias` in order. A through point neither starts a new leg nor lets the
     * engine turn round on it, so each answer is one line from `a` to `b`. One answer per ask, in order, as snap()
     * answers it; null for an ask the engine cannot route.
     *
     * @param list<array{a: array{0: float, 1: float}, b: array{0: float, 1: float}, vias: list<array{0: float, 1: float}>}> $asks [lat, lng] points
     *
     * @return list<array{coordinates: list<array{0: float, 1: float}>, distanceM: float}|null>
     */
    public function snapThroughMany(array $asks, BikeType $bike): array
    {
        $pending = array_map(fn (array $ask): ?ResponseInterface => $this->throughRequest($ask['a'], $ask['vias'], $ask['b'], $bike), $asks);

        return array_map(fn (?ResponseInterface $res): ?array => null === $res ? null : $this->snapResult($res), $pending);
    }

    /**
     * @param array{0: float, 1: float}       $a
     * @param list<array{0: float, 1: float}> $vias
     * @param array{0: float, 1: float}       $b
     */
    private function throughRequest(array $a, array $vias, array $b, BikeType $bike): ?ResponseInterface
    {
        if ('' === $this->valhallaUrl) {
            return null;
        }
        $url = $this->endpoints?->forShape([$a, $b]) ?? $this->valhallaUrl;
        $locations = [['lat' => $a[0], 'lon' => $a[1]]];
        foreach ($vias as $v) {
            $locations[] = ['lat' => $v[0], 'lon' => $v[1], 'type' => 'through'];
        }
        $locations[] = ['lat' => $b[0], 'lon' => $b[1]];
        try {
            return $this->http->request('POST', rtrim($url, '/').'/route', [
                'json' => [
                    'locations' => $locations,
                    'costing' => 'bicycle',
                    'costing_options' => ['bicycle' => BikeCosting::options($bike)],
                    'directions_type' => 'none',
                ],
                'timeout' => 8,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('route snap failed', ['error' => $e->getMessage()]);

            return null;
        }
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
    public function matchMany(array $pieces): array
    {
        $pending = array_map(fn (array $piece): ?ResponseInterface => $this->matchRequest($piece), $pieces);

        return array_map(fn (?ResponseInterface $res): ?array => null === $res ? null : $this->snapResult($res), $pending);
    }

    /**
     * @param list<array{0: float, 1: float}> $piece
     */
    private function matchRequest(array $piece): ?ResponseInterface
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
