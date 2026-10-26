<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Elevation;

use App\Catalog\SurfaceVocabulary;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The surfaces under a planned leg (docs/specs/route-planner.md section 4.4),
 * from our own routing engine: `/trace_attributes` walks the leg's line edge
 * by edge and says each edge's surface, length, road class and cycle lane,
 * and this class folds the engine's surface words into the three a rider
 * plans with, paved, gravel and rough, plus unknown, in metres, and counts
 * the busy metres: trunk, primary and secondary roads without a dedicated or
 * separated cycle lane. From the same walk: the metres on a cycle path, away
 * from cars (a cycleway, or a road with a separated lane), and the traffic
 * lights passed, for "Why this way?" (section 4.2a), and the metres per speed
 * limit, OpenStreetMap's maxspeed as the engine carries it. In a country whose
 * ways are loaded (RecordedWays) the recorded surface of each way stands for
 * the engine's guess, with its smoothness and tracktype (section 4.4a). Where
 * the leg runs along a road surface our curators set (layer A, CuratedSurfaces),
 * that row's surface and smoothness stand for both, in any country. Null
 * when there is no engine, it does not answer, or it finds no edge: the figures
 * then leave the surface out.
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
    /**
     * The main roads, by their OpenStreetMap class, when no lane of their own runs along (owner 2026-10-19: "where did
     * you get the busy from?"): a road class, never measured traffic. The figures call them main roads.
     */
    private const array BUSY_CLASSES = ['motorway', 'trunk', 'primary', 'secondary'];
    private const array OWN_LANES = ['dedicated', 'separated'];
    /** A cycle path, away from cars: a cycleway of its own, or a road whose cycle lane is kerbed off. */
    private const string CYCLEWAY_USE = 'cycleway';
    private const string APART_LANE = 'separated';
    /** The engine's edge use as the kind a rider reads on an unnamed road (owner 2026-10-15). */
    /** The pipeline's surface classes, folded the same way (coverage-contract.json, surface.classes). */
    private const array RECORDED_CLASS = ['paved' => 'paved', 'gravel' => 'gravel', 'pave' => 'rough', 'dirt' => 'rough', 'sand' => 'rough', 'rock' => 'rough'];
    /**
     * The roads whose surface nobody can guess (coverage-contract.json, surface.todo.highways: the map's "surface not
     * recorded" lines), as the engine names them: a track, or an unclassified road. One of these the table does not
     * hold has no surface recorded. Any other road keeps the engine's word where nothing is recorded, as the map
     * draws no to-do line there: an untagged main road or street is paved all but always. A path is in the to-do only
     * with a bicycle tag the engine does not say, so a path keeps the engine's word too.
     */
    private const array UNGUESSED_USES = ['track'];
    private const array UNGUESSED_ROADS = ['unclassified'];
    /**
     * An edge runs along a curated row when two of its three sample points, at a quarter, half and three quarters of
     * it, lie within ON_CURATED_M of the row's line and run with it, within PARALLEL_DEG: a road crossing the row, or
     * one beside it, keeps its own words.
     */
    private const float ON_CURATED_M = 8.0;
    private const float PARALLEL_DEG = 30.0;
    /** The smoothness labels of the road surface form, as OpenStreetMap writes them ("Very bad" is `very_bad`). */
    private const array SMOOTHNESS = ['excellent', 'good', 'intermediate', 'bad', 'very_bad'];
    private const array KIND_OF_USE = [
        'track' => 'track', 'cycleway' => 'cycleway', 'mountain_bike' => 'path', 'path' => 'path', 'footway' => 'path',
        'pedestrian' => 'path', 'steps' => 'path', 'bridleway' => 'path', 'living_street' => 'road',
    ];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $valhallaUrl,
        private readonly ?ElevationEndpoints $endpoints = null,
        private readonly ?RecordedWays $ways = null,
        private readonly ?CuratedSurfaces $curated = null,
    ) {
    }

    /**
     * @param list<array{0: float, 1: float}> $coords [lat, lng] pairs, the routed line
     *
     * @return array{paved: int, gravel: int, rough: int, unknown: int, busy: int, cycle: int, sand: int, signals: int}|null metres per class, on busy
     *                                                                                                                       roads and on cycle paths, and the traffic lights passed
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
     * @return array{surfaces: array{paved: int, gravel: int, rough: int, unknown: int, busy: int, cycle: int, sand: int, signals: int}, streets: list<array{0: int, 1: string|null, 2: string}>, runs: list<array{0: int, 1: string}>, busyRuns: list<array{0: int, 1: int}>, recorded: bool, quality: array{smoothness: array<string, int>, tracktype: array<string, int>}, speeds: array<string, int>, detailRuns: array{smoothness: list<array{0: int, 1: int, 2: string}>, tracktype: list<array{0: int, 1: int, 2: string}>, speeds: list<array{0: int, 1: int, 2: string}>}, curatedM: int, bridgeRuns: list<array{0: int, 1: int}>, tunnelRuns: list<array{0: int, 1: int}>}|null
     */
    public function read(array $coords): ?array
    {
        $res = $this->request($coords);

        return null === $res ? null : $this->result($res, $coords);
    }

    /** The longest piece of a line the engine is asked to match at once, metres: its trace has a length limit. */
    private const float PIECE_M = 50000.0;

    /**
     * A line that is not the engine's own route, a recommended route (route-domain.md §6.4, owner 2026-10-19: "A
     * recommended route should also have the road 'quality' info"): matched to the roads (`map_snap`) in pieces of at
     * most PIECE_M that share their joining point, read side by side, and joined, every stretch placed after the
     * pieces before it. The streets are left out. Null when any piece has no answer.
     *
     * @param list<array{0: float, 1: float}> $coords [lat, lng] pairs
     *
     * @return array{distanceM: int, surfaces: array{paved: int, gravel: int, rough: int, unknown: int, busy: int, cycle: int, sand: int, signals: int}, runs: list<array{0: int, 1: string}>, busyRuns: list<array{0: int, 1: int}>, recorded: bool, quality: array{smoothness: array<string, int>, tracktype: array<string, int>}, speeds: array<string, int>, detailRuns: array{smoothness: list<array{0: int, 1: int, 2: string}>, tracktype: list<array{0: int, 1: int, 2: string}>, speeds: list<array{0: int, 1: int, 2: string}>}, curatedM: int, bridgeRuns: list<array{0: int, 1: int}>, tunnelRuns: list<array{0: int, 1: int}>}|null
     */
    public function readLine(array $coords): ?array
    {
        $reads = $this->readMany(self::pieces($coords), 'map_snap');
        if ([] === $reads || \in_array(null, $reads, true)) {
            return null;
        }
        $out = ['distanceM' => 0, 'surfaces' => ['paved' => 0, 'gravel' => 0, 'rough' => 0, 'unknown' => 0, 'busy' => 0, 'cycle' => 0, 'sand' => 0, 'signals' => 0],
            'runs' => [], 'busyRuns' => [], 'recorded' => false, 'quality' => ['smoothness' => [], 'tracktype' => []], 'speeds' => [],
            'detailRuns' => ['smoothness' => [], 'tracktype' => [], 'speeds' => []], 'curatedM' => 0, 'bridgeRuns' => [], 'tunnelRuns' => []];
        /** @var list<array{surfaces: array{paved: int, gravel: int, rough: int, unknown: int, busy: int, cycle: int, sand: int, signals: int}, streets: list<array{0: int, 1: string|null, 2: string}>, runs: list<array{0: int, 1: string}>, busyRuns: list<array{0: int, 1: int}>, recorded: bool, quality: array{smoothness: array<string, int>, tracktype: array<string, int>}, speeds: array<string, int>, detailRuns: array{smoothness: list<array{0: int, 1: int, 2: string}>, tracktype: list<array{0: int, 1: int, 2: string}>, speeds: list<array{0: int, 1: int, 2: string}>}, curatedM: int, bridgeRuns: list<array{0: int, 1: int}>, tunnelRuns: list<array{0: int, 1: int}>}> $reads */
        foreach ($reads as $read) {
            $off = $out['distanceM'];
            foreach ($read['surfaces'] as $k => $m) {
                $out['surfaces'][$k] += $m;
            }
            foreach ($read['runs'] as [$at, $class]) {
                $last = array_key_last($out['runs']);
                if (null === $last || $out['runs'][$last][1] !== $class) {
                    $out['runs'][] = [$off + $at, $class];
                }
            }
            foreach ($read['busyRuns'] as [$from, $to]) {
                $last = array_key_last($out['busyRuns']);
                if (null !== $last && $out['busyRuns'][$last][1] >= $off + $from) {
                    $out['busyRuns'][$last][1] = $off + $to;
                } else {
                    $out['busyRuns'][] = [$off + $from, $off + $to];
                }
            }
            foreach (['smoothness', 'tracktype'] as $key) {
                foreach ($read['quality'][$key] as $v => $m) {
                    $out['quality'][$key][$v] = ($out['quality'][$key][$v] ?? 0) + $m;
                }
            }
            foreach ($read['speeds'] as $v => $m) {
                $out['speeds'][$v] = ($out['speeds'][$v] ?? 0) + $m;
            }
            foreach ($read['detailRuns'] as $key => $runs) {
                foreach ($runs as [$from, $to, $v]) {
                    $last = array_key_last($out['detailRuns'][$key]);
                    if (null !== $last && $out['detailRuns'][$key][$last][2] === $v && $out['detailRuns'][$key][$last][1] === $off + $from) {
                        $out['detailRuns'][$key][$last][1] = $off + $to;
                    } else {
                        $out['detailRuns'][$key][] = [$off + $from, $off + $to, $v];
                    }
                }
            }
            $out['recorded'] = $out['recorded'] || $read['recorded'];
            $out['curatedM'] += $read['curatedM'];
            foreach (['bridgeRuns', 'tunnelRuns'] as $key) {
                foreach ($read[$key] as [$from, $to]) {
                    $out[$key][] = [$off + $from, $off + $to];
                }
            }
            $out['distanceM'] += $read['surfaces']['paved'] + $read['surfaces']['gravel'] + $read['surfaces']['rough'] + $read['surfaces']['unknown'];
        }
        uksort($out['speeds'], static fn (int|string $a, int|string $b): int => ('unset' === $a ? \PHP_INT_MAX : (int) $a) <=> ('unset' === $b ? \PHP_INT_MAX : (int) $b));

        return $out;
    }

    /**
     * A line cut into pieces of at most PIECE_M along it, each starting where the one before ends.
     *
     * @param list<array{0: float, 1: float}> $coords [lat, lng] pairs
     *
     * @return list<list<array{0: float, 1: float}>>
     */
    private static function pieces(array $coords): array
    {
        $pieces = [];
        $piece = [];
        $m = 0.0;
        foreach ($coords as $i => $c) {
            if ($i > 0 && [] !== $piece) {
                $p = $coords[$i - 1];
                $dy = ($c[0] - $p[0]) * 110540.0;
                $dx = ($c[1] - $p[1]) * 111320.0 * cos(deg2rad($c[0]));
                $m += sqrt($dx * $dx + $dy * $dy);
                if ($m > self::PIECE_M && \count($piece) >= 2) {
                    $pieces[] = $piece;
                    $piece = [$p];
                    $m = sqrt($dx * $dx + $dy * $dy);
                }
            }
            $piece[] = $c;
        }
        if (\count($piece) >= 2) {
            $pieces[] = $piece;
        }

        return $pieces;
    }

    /**
     * Many legs at once (route-planner.md section 8, owner 2026-10-18): every request goes out before the first
     * answer is read, so the engine walks the legs side by side. One answer per line, in order.
     *
     * @param list<list<array{0: float, 1: float}>> $lines      [lat, lng] pairs per leg
     * @param string                                $shapeMatch `edge_walk` for the engine's own route, `map_snap` for any other line
     *
     * @return list<array{surfaces: array{paved: int, gravel: int, rough: int, unknown: int, busy: int, cycle: int, sand: int, signals: int}, streets: list<array{0: int, 1: string|null, 2: string}>, runs: list<array{0: int, 1: string}>, busyRuns: list<array{0: int, 1: int}>, recorded: bool, quality: array{smoothness: array<string, int>, tracktype: array<string, int>}, speeds: array<string, int>, detailRuns: array{smoothness: list<array{0: int, 1: int, 2: string}>, tracktype: list<array{0: int, 1: int, 2: string}>, speeds: list<array{0: int, 1: int, 2: string}>}, curatedM: int, bridgeRuns: list<array{0: int, 1: int}>, tunnelRuns: list<array{0: int, 1: int}>}|null>
     */
    public function readMany(array $lines, string $shapeMatch = 'edge_walk'): array
    {
        $pending = array_map(fn (array $coords): ?ResponseInterface => $this->request($coords, $shapeMatch), $lines);

        return array_map(fn (?ResponseInterface $res, array $coords): ?array => null === $res ? null : $this->result($res, $coords), $pending, $lines);
    }

    /**
     * @param list<array{0: float, 1: float}> $coords
     */
    private function request(array $coords, string $shapeMatch = 'edge_walk'): ?ResponseInterface
    {
        if (\count($coords) < 2 || '' === $this->valhallaUrl) {
            return null;
        }
        $url = $this->endpoints?->forShape($coords) ?? $this->valhallaUrl;
        $shape = array_map(static fn (array $c): array => ['lat' => $c[0], 'lon' => $c[1]], $coords);
        try {
            return $this->http->request('POST', rtrim($url, '/').'/trace_attributes', [
                'json' => [
                    'shape' => $shape,
                    'costing' => 'bicycle',
                    'shape_match' => $shapeMatch,
                    'units' => 'kilometers',
                    // The engine marks a traffic light on the edge that ends at it, now and then on the node itself.
                    'filters' => ['attributes' => ['edge.surface', 'edge.length', 'edge.road_class', 'edge.cycle_lane', 'edge.names', 'edge.use', 'edge.traffic_signal', 'node.traffic_signal', 'edge.way_id', 'edge.speed_limit', 'edge.begin_shape_index', 'edge.end_shape_index', 'shape', 'edge.bridge', 'edge.tunnel'], 'action' => 'include'],
                ],
                'timeout' => 8,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('leg surfaces lookup failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param list<array{0: float, 1: float}> $coords
     *
     * @return array{surfaces: array{paved: int, gravel: int, rough: int, unknown: int, busy: int, cycle: int, sand: int, signals: int}, streets: list<array{0: int, 1: string|null, 2: string}>, runs: list<array{0: int, 1: string}>, busyRuns: list<array{0: int, 1: int}>, recorded: bool, quality: array{smoothness: array<string, int>, tracktype: array<string, int>}, speeds: array<string, int>, detailRuns: array{smoothness: list<array{0: int, 1: int, 2: string}>, tracktype: list<array{0: int, 1: int, 2: string}>, speeds: list<array{0: int, 1: int, 2: string}>}, curatedM: int, bridgeRuns: list<array{0: int, 1: int}>, tunnelRuns: list<array{0: int, 1: int}>}|null
     */
    private function result(ResponseInterface $res, array $coords): ?array
    {
        try {
            $data = $res->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('leg surfaces lookup failed', ['error' => $e->getMessage()]);

            return null;
        }
        $edges = $data['edges'] ?? null;
        if (!\is_array($edges) || [] === $edges) {
            return null;
        }
        $recorded = $this->ways?->forLeg($coords[0][0], $coords[0][1], array_values(array_filter(array_map(
            static fn (mixed $e): int => \is_array($e) && is_numeric($e['way_id'] ?? null) ? (int) $e['way_id'] : 0,
            $edges,
        ))));
        // The engine's own line, as matched: each edge's piece of it is told by its shape indices.
        $shape = \is_string($data['shape'] ?? null) ? self::decodePolyline6($data['shape']) : [];
        $curated = null === $this->curated || [] === $shape ? [] : $this->curated->near($shape);
        $quality = ['smoothness' => [], 'tracktype' => []];
        $speeds = [];
        // Where each smoothness, track grade and speed limit lies along the leg, [from, to, value] in metres, a run of
        // one value merged: the panel lights its stretches on the map (owner 2026-10-19: "I can't hover it").
        $detailRuns = ['smoothness' => [], 'tracktype' => [], 'speeds' => []];
        $span = static function (array &$list, float $from, float $to, string $value): void {
            $last = array_key_last($list);
            if (null !== $last && $list[$last][2] === $value && $list[$last][1] === (int) round($from)) {
                $list[$last][1] = (int) round($to);

                return;
            }
            $list[] = [(int) round($from), (int) round($to), $value];
        };
        // `sand` is part of `rough`, counted apart as well: a gravel bike seeks rough ground, never sand (route-planner.md 4.2b).
        $metres = ['paved' => 0.0, 'gravel' => 0.0, 'rough' => 0.0, 'unknown' => 0.0, 'busy' => 0.0, 'cycle' => 0.0, 'sand' => 0.0];
        // The metres along a curated row: what our curators set, not what OpenStreetMap records.
        $curatedM = 0.0;
        $signals = 0;
        $streets = [];
        // Where each surface class starts along the leg, one run per class change (route-planner.md section 4.4a): the
        // panel lights up the stretches of one class on the map when the rider points at it.
        $runs = [];
        // The busy stretches, [from, to] metres along the leg: what makes it tough lights them up (section 4.4).
        $busyRuns = [];
        // On a bridge and in a tunnel, [from, to] metres along the leg: BridgeHeights lays the deck there.
        $structureRuns = ['bridge' => [], 'tunnel' => []];
        $at = 0.0;
        foreach ($edges as $edge) {
            if (!\is_array($edge) || !isset($edge['length']) || !is_numeric($edge['length'])) {
                continue;
            }
            $length = (float) $edge['length'] * 1000.0;
            $use = \is_string($edge['use'] ?? null) ? $edge['use'] : '';
            $road = \is_string($edge['road_class'] ?? null) ? $edge['road_class'] : '';
            $class = self::CLASS_OF[\is_string($edge['surface'] ?? null) ? $edge['surface'] : ''] ?? 'unknown';
            $sand = false;
            $detail = ['smoothness' => null, 'tracktype' => null];
            if (null !== $recorded) {
                $way = $recorded[is_numeric($edge['way_id'] ?? null) ? (int) $edge['way_id'] : 0] ?? null;
                if (null !== $way && null !== $way['surface']) {
                    $class = self::RECORDED_CLASS[$way['surface']] ?? 'unknown';
                    $sand = 'sand' === $way['surface'];
                } elseif (\in_array($use, self::UNGUESSED_USES, true) || ('road' === $use && \in_array($road, self::UNGUESSED_ROADS, true))) {
                    $class = 'unknown';
                }
                $detail = ['smoothness' => $way['smoothness'] ?? null, 'tracktype' => $way['tracktype'] ?? null];
            }
            $own = [] === $curated ? null : self::curatedOn($edge, $shape, $curated);
            if (null !== $own) {
                $curatedM += $length;
                $tile = SurfaceVocabulary::tileClassFor($own['surface']);
                if (null !== $tile && isset(self::RECORDED_CLASS[$tile])) {
                    $class = self::RECORDED_CLASS[$tile];
                    $sand = 'sand' === $tile;
                }
                $smooth = null === $own['smoothness'] ? '' : str_replace(' ', '_', strtolower(trim($own['smoothness'])));
                if (\in_array($smooth, self::SMOOTHNESS, true)) {
                    $detail['smoothness'] = $smooth;
                }
            }
            if ($sand) {
                $metres['sand'] += $length;
            }
            foreach ($detail as $key => $value) {
                if (null !== $value) {
                    $quality[$key][$value] = ($quality[$key][$value] ?? 0.0) + $length;
                    $span($detailRuns[$key], $at, $at + $length, $value);
                }
            }
            $limit = is_numeric($edge['speed_limit'] ?? null) && (int) $edge['speed_limit'] > 0 ? (string) (int) $edge['speed_limit'] : 'unset';
            $speeds[$limit] = ($speeds[$limit] ?? 0.0) + $length;
            $span($detailRuns['speeds'], $at, $at + $length, $limit);
            $metres[$class] += $length;
            $lastRun = [] === $runs ? null : end($runs);
            if (null === $lastRun || $lastRun[1] !== $class) {
                $runs[] = [(int) round($at), $class];
            }
            $lane = \is_string($edge['cycle_lane'] ?? null) ? $edge['cycle_lane'] : 'none';
            if (\in_array($road, self::BUSY_CLASSES, true) && !\in_array($lane, self::OWN_LANES, true)) {
                $metres['busy'] += $length;
                $last = array_key_last($busyRuns);
                if (null !== $last && $busyRuns[$last][1] >= (int) round($at)) {
                    $busyRuns[$last][1] = (int) round($at + $length);
                } else {
                    $busyRuns[] = [(int) round($at), (int) round($at + $length)];
                }
            }
            foreach (['bridge', 'tunnel'] as $kind) {
                if (true !== ($edge[$kind] ?? null)) {
                    continue;
                }
                $last = array_key_last($structureRuns[$kind]);
                if (null !== $last && $structureRuns[$kind][$last][1] >= (int) round($at)) {
                    $structureRuns[$kind][$last][1] = (int) round($at + $length);
                } else {
                    $structureRuns[$kind][] = [(int) round($at), (int) round($at + $length)];
                }
            }
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
        $round = static fn (array $m): array => array_map(static fn (float $v): int => (int) round($v), $m);
        // The limits in order, slowest first, and the metres with none set last.
        uksort($speeds, static fn (int|string $a, int|string $b): int => ('unset' === $a ? \PHP_INT_MAX : (int) $a) <=> ('unset' === $b ? \PHP_INT_MAX : (int) $b));

        return ['surfaces' => $surfaces, 'streets' => $streets, 'runs' => $runs, 'busyRuns' => $busyRuns,
            'recorded' => null !== $recorded,
            'quality' => ['smoothness' => $round($quality['smoothness']), 'tracktype' => $round($quality['tracktype'])],
            'speeds' => $round($speeds),
            'detailRuns' => $detailRuns,
            'curatedM' => (int) round($curatedM),
            'bridgeRuns' => $structureRuns['bridge'],
            'tunnelRuns' => $structureRuns['tunnel']];
    }

    /**
     * The curated row an edge runs along, or null.
     *
     * @param array<mixed>                                                                                      $edge
     * @param list<array{0: float, 1: float}>                                                                   $shape   the engine's line, [lat, lng]
     * @param list<array{line: list<array{0: float, 1: float}>, surface: string|null, smoothness: string|null}> $curated
     *
     * @return array{line: list<array{0: float, 1: float}>, surface: string|null, smoothness: string|null}|null
     */
    private static function curatedOn(array $edge, array $shape, array $curated): ?array
    {
        $from = is_numeric($edge['begin_shape_index'] ?? null) ? (int) $edge['begin_shape_index'] : -1;
        $to = is_numeric($edge['end_shape_index'] ?? null) ? (int) $edge['end_shape_index'] : -1;
        if ($from < 0 || $to <= $from || !isset($shape[$to])) {
            return null;
        }
        $samples = self::samples(\array_slice($shape, $from, $to - $from + 1));
        $parallel = cos(deg2rad(self::PARALLEL_DEG));
        foreach ($curated as $row) {
            $on = 0;
            foreach ($samples as [$p, $dir]) {
                if (self::runsAlong($p, $dir, $row['line'], $parallel)) {
                    ++$on;
                }
            }
            if ($on >= 2) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Three points on a piece of line, at a quarter, half and three quarters of its length, each with the unit
     * direction of the line there in local metres (east, north).
     *
     * @param list<array{0: float, 1: float}> $piece [lat, lng]
     *
     * @return list<array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}}>
     */
    private static function samples(array $piece): array
    {
        $steps = [];
        $total = 0.0;
        for ($i = 1, $n = \count($piece); $i < $n; ++$i) {
            [$dx, $dy] = self::metres($piece[$i - 1], $piece[$i]);
            $len = sqrt($dx * $dx + $dy * $dy);
            $steps[] = [$piece[$i - 1], $piece[$i], $len, $dx, $dy];
            $total += $len;
        }
        if ($total <= 0.0) {
            return [];
        }
        $out = [];
        foreach ([0.25, 0.5, 0.75] as $share) {
            $want = $total * $share;
            foreach ($steps as [$a, $b, $len, $dx, $dy]) {
                if ($len > 0.0 && $want <= $len) {
                    $f = $want / $len;
                    $out[] = [[$a[0] + ($b[0] - $a[0]) * $f, $a[1] + ($b[1] - $a[1]) * $f], [$dx / $len, $dy / $len]];
                    break;
                }
                $want -= $len;
            }
        }

        return $out;
    }

    /**
     * Whether a point lies within ON_CURATED_M of a line, on a stretch of it that runs with the given direction.
     *
     * @param array{0: float, 1: float}       $p    [lat, lng]
     * @param array{0: float, 1: float}       $dir  unit direction (east, north)
     * @param list<array{0: float, 1: float}> $line [lat, lng]
     */
    private static function runsAlong(array $p, array $dir, array $line, float $parallel): bool
    {
        for ($i = 1, $n = \count($line); $i < $n; ++$i) {
            [$ax, $ay] = self::metres($p, $line[$i - 1]);
            [$bx, $by] = self::metres($p, $line[$i]);
            $sx = $bx - $ax;
            $sy = $by - $ay;
            $len2 = $sx * $sx + $sy * $sy;
            if ($len2 <= 0.0) {
                continue;
            }
            // The nearest point of the stretch to $p, which sits at the origin.
            $f = max(0.0, min(1.0, -($ax * $sx + $ay * $sy) / $len2));
            $cx = $ax + $sx * $f;
            $cy = $ay + $sy * $f;
            if ($cx * $cx + $cy * $cy > self::ON_CURATED_M ** 2) {
                continue;
            }
            if (abs(($sx * $dir[0] + $sy * $dir[1]) / sqrt($len2)) >= $parallel) {
                return true;
            }
        }

        return false;
    }

    /**
     * From one point to another in local metres, east and north.
     *
     * @param array{0: float, 1: float} $a [lat, lng]
     * @param array{0: float, 1: float} $b [lat, lng]
     *
     * @return array{0: float, 1: float}
     */
    private static function metres(array $a, array $b): array
    {
        return [($b[1] - $a[1]) * 111320.0 * cos(deg2rad($a[0])), ($b[0] - $a[0]) * 110540.0];
    }

    /**
     * The engine's encoded line, Google's polyline at six decimals, as [lat, lng] pairs.
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function decodePolyline6(string $encoded): array
    {
        $out = [];
        $at = 0;
        $len = \strlen($encoded);
        $lat = 0;
        $lng = 0;
        while ($at < $len) {
            foreach ([0, 1] as $k) {
                $shift = 0;
                $value = 0;
                do {
                    if ($at >= $len) {
                        return $out;
                    }
                    $b = \ord($encoded[$at++]) - 63;
                    $value |= ($b & 0x1F) << $shift;
                    $shift += 5;
                } while ($b >= 0x20);
                $delta = ($value & 1) ? ~($value >> 1) : $value >> 1;
                if (0 === $k) {
                    $lat += $delta;
                } else {
                    $lng += $delta;
                }
            }
            $out[] = [$lat / 1e6, $lng / 1e6];
        }

        return $out;
    }
}
