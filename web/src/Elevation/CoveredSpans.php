<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Elevation;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Which stretches of a climb run under cover — tunnels and avalanche galleries.
 *
 * A Digital Surface Model reads the first surface the sensor saw, so where the
 * road is roofed it returns the mountain on top of it. On the Grimsel that is a
 * 54 m step in 140 m followed by a flat, which is a gallery rather than a wall
 * of tarmac; 2,082 m of that climb — 8% of it — is covered. Left in, those steps
 * dominate any steepest-stretch figure: Grimsel measured 15.1% against a real
 * ~11% even after the percentile in {@see ClimbProfiler} had removed the worst
 * of the noise.
 *
 * The road network already knows. Valhalla's `/trace_attributes` map-matches a
 * shape onto real edges and reports OSM's `tunnel` flag per edge, so this is a
 * lookup rather than a guess — no heuristic about what a step in the profile
 * "probably" means.
 *
 * **Spans come back as fractions of the line, not metres.** Map-matching snaps
 * to the carriageway, so the matched geometry is not the shape that was sent and
 * its length differs slightly. A fraction survives that; a metre offset silently
 * drifts along the climb.
 *
 * @see docs/specs/climb-elevation.md §2a
 *
 * @api Used by App\Elevation\ClimbProfiler.
 */
final class CoveredSpans
{
    /**
     * Matching cost grows fast with shape length, and the caller samples to 200
     * points anyway. This is a guard against an unbounded upstream read, not a
     * resolution limit: matching follows the ROAD between samples, so a 33 m
     * tunnel is still found from points 130 m apart. Measured on Grimsel,
     * Susten and Klausen, the 200-point shape returns byte-identical spans to
     * the full 690-point route.
     */
    public const int MAX_POINTS = 400;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly ElevationEndpoints $endpoints,
    ) {
    }

    /**
     * @param list<array{0: float, 1: float}> $coords [lat, lng] pairs
     *
     * @return list<array{0: float, 1: float}> [startFraction, endFraction] pairs,
     *                                         0..1 along the line, merged and ordered. EMPTY when nothing is
     *                                         covered AND when the lookup fails — a climb measured without this
     *                                         is exactly today's answer, so a routing outage costs accuracy on
     *                                         roofed roads and never a missing profile.
     */
    public function forShape(array $coords): array
    {
        $url = $this->endpoints->forShape($coords);
        if (\count($coords) < 2 || \count($coords) > self::MAX_POINTS || '' === $url) {
            return [];
        }

        try {
            $res = $this->http->request('POST', rtrim($url, '/').'/trace_attributes', [
                'json' => [
                    'shape' => array_map(
                        static fn (array $c): array => ['lat' => $c[0], 'lon' => $c[1]],
                        $coords,
                    ),
                    'costing' => 'auto',
                    // map_snap, not edge_walk: the stored line came from a router
                    // and may sit metres off the carriageway, which edge_walk
                    // refuses outright.
                    'shape_match' => 'map_snap',
                    'filters' => [
                        'attributes' => ['edge.tunnel', 'edge.begin_shape_index', 'edge.end_shape_index', 'shape'],
                        'action' => 'include',
                    ],
                ],
                'timeout' => 8,
            ]);
            $data = $res->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('covered-span lookup failed', ['error' => $e->getMessage()]);

            return [];
        }

        $shape = \is_string($data['shape'] ?? null) ? self::decodePolyline6($data['shape']) : [];
        $edges = \is_array($data['edges'] ?? null) ? $data['edges'] : [];
        if (\count($shape) < 2 || [] === $edges) {
            return [];
        }

        $cum = [0.0];
        for ($i = 1, $n = \count($shape); $i < $n; ++$i) {
            // Append rather than write $cum[$i]: same order, same values, but
            // Psalm can keep treating $cum as a list instead of losing the
            // shape at the first non-literal key.
            $cum[] = $cum[$i - 1] + self::haversine($shape[$i - 1], $shape[$i]);
        }
        $total = $cum[\count($cum) - 1];
        if ($total <= 0.0) {
            return [];
        }

        $spans = [];
        foreach ($edges as $edge) {
            if (!\is_array($edge) || true !== ($edge['tunnel'] ?? false)) {
                continue;
            }
            $b = (int) ($edge['begin_shape_index'] ?? -1);
            $e = (int) ($edge['end_shape_index'] ?? -1);
            $b = max(0, min($b, \count($cum) - 1));
            $e = max(0, min($e, \count($cum) - 1));
            if ($e > $b) {
                $spans[] = [$cum[$b] / $total, $cum[$e] / $total];
            }
        }

        return self::merge($spans);
    }

    /**
     * @param list<array{0: float, 1: float}> $spans
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function merge(array $spans): array
    {
        if ([] === $spans) {
            return [];
        }
        usort($spans, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $out = [array_shift($spans)];
        foreach ($spans as [$a, $b]) {
            $last = \count($out) - 1;
            // Consecutive tunnel edges of one bore arrive separately; joining
            // them keeps a single gallery from reading as several.
            if ($a <= $out[$last][1]) {
                $out[$last][1] = max($out[$last][1], $b);
            } else {
                $out[] = [$a, $b];
            }
        }

        return $out;
    }

    /**
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     */
    private static function haversine(array $a, array $b): float
    {
        $r = 6371000.0;
        $p1 = deg2rad($a[0]);
        $p2 = deg2rad($b[0]);
        $dp = $p2 - $p1;
        $dl = deg2rad($b[1] - $a[1]);
        $x = \sin($dp / 2) ** 2 + \cos($p1) * \cos($p2) * \sin($dl / 2) ** 2;

        return 2 * $r * \asin(min(1.0, \sqrt($x)));
    }

    /**
     * Valhalla encodes shapes as polyline6 — the Google algorithm at 1e6 rather
     * than 1e5.
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function decodePolyline6(string $encoded): array
    {
        $out = [];
        $lat = 0;
        $lng = 0;
        $i = 0;
        $len = \strlen($encoded);
        while ($i < $len) {
            foreach ([0, 1] as $axis) {
                $shift = 0;
                $result = 0;
                do {
                    if ($i >= $len) {
                        return $out;
                    }
                    $b = \ord($encoded[$i++]) - 63;
                    $result |= ($b & 0x1F) << $shift;
                    $shift += 5;
                } while ($b >= 0x20);
                $d = (1 === ($result & 1)) ? ~($result >> 1) : ($result >> 1);
                if (0 === $axis) {
                    $lat += $d;
                } else {
                    $lng += $d;
                }
            }
            $out[] = [$lat / 1e6, $lng / 1e6];
        }

        return $out;
    }
}
