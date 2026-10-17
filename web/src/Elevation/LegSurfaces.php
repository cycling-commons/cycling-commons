<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Elevation;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The surfaces under a planned leg (docs/specs/route-planner.md section 4.4),
 * from our own routing engine: `/trace_attributes` walks the leg's line edge
 * by edge and says each edge's surface, length, road class and cycle lane,
 * and this class folds the engine's surface words into the three a rider
 * plans with, paved, gravel and rough, plus unknown, in metres, and counts
 * the busy metres: trunk, primary and secondary roads without a dedicated or
 * separated cycle lane. From the same walk: the metres on a cycle path, away
 * from cars (a cycleway, or a road with a separated lane), and the traffic
 * lights passed, for "Why this way?" (section 4.2a). Null when there is no
 * engine, it does not answer, or it finds no edge: the figures then leave the
 * surface out.
 *
 * @api
 */
final class LegSurfaces
{
    /** The engine's surface words, folded (docs/specs/route-planner.md section 4.4). */
    private const array CLASS_OF = [
        'paved_smooth' => 'paved',
        'paved' => 'paved',
        'paved_rough' => 'rough',
        'compacted' => 'gravel',
        'gravel' => 'gravel',
        'dirt' => 'rough',
        'path' => 'rough',
        'impassable' => 'rough',
    ];
    /** The road classes a rider calls busy, when no lane of their own runs along. */
    private const array BUSY_CLASSES = ['motorway', 'trunk', 'primary', 'secondary'];
    private const array OWN_LANES = ['dedicated', 'separated'];
    /** A cycle path, away from cars: a cycleway of its own, or a road whose cycle lane is kerbed off. */
    private const string CYCLEWAY_USE = 'cycleway';
    private const string APART_LANE = 'separated';
    /** The engine's edge use as the kind a rider reads on an unnamed road (owner 2026-10-15). */
    private const array KIND_OF_USE = [
        'track' => 'track', 'cycleway' => 'cycleway', 'mountain_bike' => 'path', 'path' => 'path', 'footway' => 'path',
        'pedestrian' => 'path', 'steps' => 'path', 'bridleway' => 'path', 'living_street' => 'road',
    ];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $valhallaUrl,
        private readonly ?ElevationEndpoints $endpoints = null,
    ) {
    }

    /**
     * @param list<array{0: float, 1: float}> $coords [lat, lng] pairs, the routed line
     *
     * @return array{paved: int, gravel: int, rough: int, unknown: int, busy: int, cycle: int, signals: int}|null metres per class, on busy
     *                                                                                                            roads and on cycle paths, and the traffic lights passed
     */
    public function along(array $coords): ?array
    {
        return $this->read($coords)['surfaces'] ?? null;
    }

    /**
     * One walk of the leg for both answers: the surfaces, and the street names
     * with the metre each starts at and the road's kind (road, track, path,
     * cycleway), a run of one name merged, an unnamed road's name null (route-planner.md section 4.4a; owner 2026-10-15: "I miss the street
     * names"). The names are the engine's own, from OpenStreetMap: nothing
     * leaves our servers for them.
     *
     * @param list<array{0: float, 1: float}> $coords [lat, lng] pairs, the routed line
     *
     * @return array{surfaces: array{paved: int, gravel: int, rough: int, unknown: int, busy: int, cycle: int, signals: int}, streets: list<array{0: int, 1: string|null, 2: string}>}|null
     */
    public function read(array $coords): ?array
    {
        if (\count($coords) < 2 || '' === $this->valhallaUrl) {
            return null;
        }
        $url = $this->endpoints?->forShape($coords) ?? $this->valhallaUrl;
        $shape = array_map(static fn (array $c): array => ['lat' => $c[0], 'lon' => $c[1]], $coords);
        try {
            $res = $this->http->request('POST', rtrim($url, '/').'/trace_attributes', [
                'json' => [
                    'shape' => $shape,
                    'costing' => 'bicycle',
                    'shape_match' => 'edge_walk',
                    'units' => 'kilometers',
                    // The engine marks a traffic light on the edge that ends at it, now and then on the node itself.
                    'filters' => ['attributes' => ['edge.surface', 'edge.length', 'edge.road_class', 'edge.cycle_lane', 'edge.names', 'edge.use', 'edge.traffic_signal', 'node.traffic_signal'], 'action' => 'include'],
                ],
                'timeout' => 8,
            ]);
            $data = $res->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('leg surfaces lookup failed', ['error' => $e->getMessage()]);

            return null;
        }
        $edges = $data['edges'] ?? null;
        if (!\is_array($edges) || [] === $edges) {
            return null;
        }
        $metres = ['paved' => 0.0, 'gravel' => 0.0, 'rough' => 0.0, 'unknown' => 0.0, 'busy' => 0.0, 'cycle' => 0.0];
        $signals = 0;
        $streets = [];
        $at = 0.0;
        foreach ($edges as $edge) {
            if (!\is_array($edge) || !isset($edge['length']) || !is_numeric($edge['length'])) {
                continue;
            }
            $length = (float) $edge['length'] * 1000.0;
            $class = self::CLASS_OF[\is_string($edge['surface'] ?? null) ? $edge['surface'] : ''] ?? 'unknown';
            $metres[$class] += $length;
            $road = \is_string($edge['road_class'] ?? null) ? $edge['road_class'] : '';
            $lane = \is_string($edge['cycle_lane'] ?? null) ? $edge['cycle_lane'] : 'none';
            if (\in_array($road, self::BUSY_CLASSES, true) && !\in_array($lane, self::OWN_LANES, true)) {
                $metres['busy'] += $length;
            }
            $use = \is_string($edge['use'] ?? null) ? $edge['use'] : '';
            if (self::CYCLEWAY_USE === $use || self::APART_LANE === $lane) {
                $metres['cycle'] += $length;
            }
            $node = \is_array($edge['end_node'] ?? null) ? $edge['end_node'] : [];
            if (true === ($edge['traffic_signal'] ?? null) || true === ($node['traffic_signal'] ?? null)) {
                ++$signals;
            }
            $names = \is_array($edge['names'] ?? null) ? array_values(array_filter($edge['names'], 'is_string')) : [];
            $name = $names[0] ?? null;
            $kind = self::KIND_OF_USE[$use] ?? 'road';
            $last = [] === $streets ? null : end($streets);
            // One run per name; an unnamed run per kind, so a track after an unnamed road reads as a track.
            if (null === $last || $last[1] !== $name || (null === $name && $last[2] !== $kind)) {
                $streets[] = [(int) round($at), $name, $kind];
            }
            $at += $length;
        }

        $surfaces = array_map(static fn (float $m): int => (int) round($m), $metres);
        $surfaces['signals'] = $signals;

        return ['surfaces' => $surfaces, 'streets' => $streets];
    }
}
