<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution\Gpx;

/**
 * Geometry post-processing for proposed routes (docs/specs/route-domain.md §4.2,
 * docs/specs/edit-items/R-quality-rides.md).
 *
 * @api
 */
final class TrackProcessor
{
    private const float EARTH_RADIUS_M = 6_371_000.0;
    private const int TRIM_MIN_M = 350;   // privacy trim, docs/specs/route-domain.md §4.3
    private const int TRIM_SPAN_M = 401;  // 350 + [0..400] → 350..750
    // Bound Douglas-Peucker inner-scan work (docs/specs/route-domain.md §4.1).
    private const int MAX_DP_WORK_FACTOR = 200;

    /** @param list<array{0: float, 1: float, 2: float|null}> $points */
    public function distanceM(array $points): float
    {
        $total = 0.0;
        for ($i = 1, $n = \count($points); $i < $n; ++$i) {
            $total += $this->haversineM($points[$i - 1], $points[$i]);
        }

        return $total;
    }

    /**
     * Positive elevation gain; null when any point lacks <ele> (docs/specs/route-domain.md §4.2).
     *
     * @param list<array{0: float, 1: float, 2: float|null}> $points
     */
    public function ascentM(array $points): ?int
    {
        // No meaningful ascent on <2 points.
        if (\count($points) < 2) {
            return null;
        }

        $gain = 0.0;
        for ($i = 0, $n = \count($points); $i < $n; ++$i) {
            if (null === $points[$i][2]) {
                return null;
            }
            if ($i > 0 && $points[$i][2] > $points[$i - 1][2]) {
                $gain += $points[$i][2] - $points[$i - 1][2];
            }
        }

        return (int) round($gain);
    }

    /**
     * Privacy trim ~350–750 m per end, deterministic from $seedHex (docs/specs/route-domain.md §4.3).
     *
     * @param list<array{0: float, 1: float, 2: float|null}> $points
     *
     * @return list<array{0: float, 1: float, 2: float|null}>
     */
    public function trim(array $points, string $seedHex): array
    {
        $startCut = (float) (self::TRIM_MIN_M + (hexdec(substr($seedHex, 0, 8)) % self::TRIM_SPAN_M));
        $endCut = (float) (self::TRIM_MIN_M + (hexdec(substr($seedHex, 8, 8)) % self::TRIM_SPAN_M));

        $trimmed = $this->cutFromStart($points, $startCut);

        return array_reverse($this->cutFromStart(array_reverse($trimmed), $endCut));
    }

    /**
     * Douglas-Peucker in metres; elevation of kept points is preserved.
     *
     * @param list<array{0: float, 1: float, 2: float|null}> $points
     *
     * @return list<array{0: float, 1: float, 2: float|null}>
     */
    public function simplify(array $points, float $toleranceM = 10.0): array
    {
        if (\count($points) < 3) {
            return $points;
        }

        // Radial pre-decimation (docs/specs/route-domain.md §4.2); first/last always kept.
        $points = $this->radialDecimate($points, $toleranceM / 2.0);
        if (\count($points) < 3) {
            return $points;
        }

        $keep = array_fill(0, \count($points), false);
        $keep[0] = $keep[\count($points) - 1] = true;
        $this->dpMark($points, 0, \count($points) - 1, $toleranceM, $keep, self::MAX_DP_WORK_FACTOR * \count($points));

        return array_values(array_intersect_key($points, array_filter($keep)));
    }

    /**
     * Keep a point only when it is ≥ $minGapM from the last kept point. First/last always kept.
     *
     * @param list<array{0: float, 1: float, 2: float|null}> $points
     *
     * @return list<array{0: float, 1: float, 2: float|null}>
     */
    private function radialDecimate(array $points, float $minGapM): array
    {
        $n = \count($points);
        if ($n < 3) {
            return $points;
        }

        $out = [$points[0]];
        $last = $points[0];
        for ($i = 1; $i < $n - 1; ++$i) {
            if ($this->haversineM($last, $points[$i]) >= $minGapM) {
                $out[] = $points[$i];
                $last = $points[$i];
            }
        }
        $out[] = $points[$n - 1];

        return $out;
    }

    /**
     * @param array{0: float, 1: float, 2: float|null} $a
     * @param array{0: float, 1: float, 2: float|null} $b
     */
    private function haversineM(array $a, array $b): float
    {
        $latA = deg2rad($a[0]);
        $latB = deg2rad($b[0]);
        $dLat = $latB - $latA;
        $dLng = deg2rad($b[1] - $a[1]);
        $h = sin($dLat / 2.0) ** 2.0 + cos($latA) * cos($latB) * sin($dLng / 2.0) ** 2.0;

        return 2.0 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($h)));
    }

    /**
     * @param list<array{0: float, 1: float, 2: float|null}> $points
     *
     * @return list<array{0: float, 1: float, 2: float|null}>
     */
    private function cutFromStart(array $points, float $cutM): array
    {
        $walked = 0.0;
        for ($i = 1, $n = \count($points); $i < $n; ++$i) {
            $seg = $this->haversineM($points[$i - 1], $points[$i]);
            if ($walked + $seg > $cutM) {
                $t = $seg > 0.0 ? ($cutM - $walked) / $seg : 0.0;
                $p = $points[$i - 1];
                $q = $points[$i];
                $newStart = [
                    $p[0] + ($q[0] - $p[0]) * $t,
                    $p[1] + ($q[1] - $p[1]) * $t,
                    (null !== $p[2] && null !== $q[2]) ? $p[2] + ($q[2] - $p[2]) * $t : null,
                ];

                return [$newStart, ...\array_slice($points, $i)];
            }
            $walked += $seg;
        }

        // Track shorter than the cut: keep the last two points so downstream always has a line.
        return \array_slice($points, -2);
    }

    /**
     * @param list<array{0: float, 1: float, 2: float|null}> $points
     * @param array<int, bool>                               $keep
     *
     * @throws \InvalidArgumentException when inner-scan work exceeds $budget (docs/specs/route-domain.md §4.1)
     */
    private function dpMark(array $points, int $first, int $last, float $tolM, array &$keep, int $budget): void
    {
        // Worklist instead of recursion: a near-collinear megatrack would overflow PHP's stack.
        /** @var list<array{0: int, 1: int}> $stack */
        $stack = [[$first, $last]];
        $work = 0;

        while ([] !== $stack) {
            [$lo, $hi] = array_pop($stack);
            if ($hi <= $lo + 1) {
                continue;
            }

            // Adversarial saw-tooth is O(n^2); track scan cost against the budget.
            $work += $hi - $lo;
            if ($work > $budget) {
                throw new \InvalidArgumentException('contribute.error.route_too_complex');
            }

            // Local equirectangular projection: metres east/north of $lo.
            $lat0 = deg2rad($points[$lo][0]);
            $cos0 = cos($lat0);
            $proj = static fn (array $p): array => [
                deg2rad($p[1]) * $cos0 * self::EARTH_RADIUS_M,
                deg2rad($p[0]) * self::EARTH_RADIUS_M,
            ];
            [$ax, $ay] = $proj($points[$lo]);
            [$bx, $by] = $proj($points[$hi]);
            $abLen2 = ($bx - $ax) ** 2.0 + ($by - $ay) ** 2.0;

            $maxDist = -1.0;
            $maxIdx = $lo;
            for ($i = $lo + 1; $i < $hi; ++$i) {
                [$px, $py] = $proj($points[$i]);
                if ($abLen2 <= 0.0) {
                    $d = hypot($px - $ax, $py - $ay);
                } else {
                    $t = max(0.0, min(1.0, (($px - $ax) * ($bx - $ax) + ($py - $ay) * ($by - $ay)) / $abLen2));
                    $d = hypot($px - ($ax + $t * ($bx - $ax)), $py - ($ay + $t * ($by - $ay)));
                }
                if ($d > $maxDist) {
                    $maxDist = $d;
                    $maxIdx = $i;
                }
            }

            if ($maxDist > $tolM) {
                $keep[$maxIdx] = true;
                $stack[] = [$lo, $maxIdx];
                $stack[] = [$maxIdx, $hi];
            }
        }
    }
}
