<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Elevation;

/**
 * Measures a climb from its drawn line (docs/specs/climb-elevation.md §3, §4, §5).
 *
 * @see docs/specs/climb-elevation.md §3, §4, §5
 *
 * @api
 */
final class ClimbProfiler
{
    /** Bin widths the display profile may use, narrowest first (docs/specs/climb-elevation.md §3b). */
    private const array BIN_LADDER = [100, 150, 200, 250, 500, 1000, 2000];

    /** Most bars a chart may draw. */
    private const int MAX_BARS = 25;

    /** Window for a published steepest figure (docs/specs/climb-elevation.md §3a, §5). */
    private const int MAX_WINDOW_M = 250;

    /** Publish the 95th-percentile window, not the raw maximum (docs/specs/climb-elevation.md §5). */
    private const float STEEPEST_PERCENTILE = 0.95;

    /** Bin width for the ascent-only average; fixed, not the display ladder. */
    private const int AVG_BIN_M = 100;

    /** Samples along the line. ~20 m spacing on a 4 km climb (docs/specs/climb-elevation.md §3c). */
    private const int SAMPLES = 200;

    /** Drop (m) a trailing stretch must lose before it counts as past the summit (docs/specs/climb-elevation.md §4a). */
    private const float OVERSHOOT_DROP_M = 8.0;

    public function __construct(
        private readonly ElevationClient $elevation,
        /** Tunnels/galleries excluded from the steepest search; null skips cover. */
        private readonly ?CoveredSpans $covered = null,
    ) {
    }

    /**
     * @param list<array{0: float, 1: float}> $route   [lat, lng], foot to summit
     * @param array{0: float, 1: float}|null  $steepAt hand-placed marker; re-measured in place (docs/specs/climb-elevation.md §5)
     *
     * @return array{
     *     length: float, gain: float, footEle: float, summitEle: float,
     *     avgGradient: string, maxGradient: string,
     *     grad: list<int>, lineGrad: list<int>,
     *     steep: array{at: array{0: float, 1: float}, pct: string, manual: bool},
     *     demSource: string, binM: int, steepWindowM: int, reversed: bool,
     *     overshootM: float,
     *     overshootDropM: float, sustainedAtSteep: string|null
     * }|null null when elevation could not be established (§2d)
     */
    public function profile(array $route, ?array $steepAt = null): ?array
    {
        if (\count($route) < 2) {
            return null;
        }
        // Distances come from the full road, not the 200 elevation samples.
        ['pts' => $pts, 'cum' => $cum] = self::sampleWithDistance($route, self::SAMPLES);
        $read = $this->elevation->heights($pts);
        if (null === $read) {
            return null;
        }
        $elev = $read['elevations'];

        // Last index at the maximum, not the first: a plateau must not end at the start of the flat.
        $si = 0;
        foreach ($elev as $i => $e) {
            if ($e >= $elev[$si]) {
                $si = $i;
            }
        }
        $total = $cum[$si];
        $reversed = 0 === $si;

        $tail = $cum[\count($cum) - 1] - $total;
        $drop = $elev[$si] - $elev[\count($elev) - 1];

        if ($reversed || $total <= 0.0) {
            // Refuse a descending line (docs/specs/climb-elevation.md §4a).
            return null;
        }

        $gain = $elev[$si] - $elev[0];
        // Cover fractions → this line's metres; only a proportion survives map-match.
        $skip = array_map(
            static fn (array $s): array => [$s[0] * $total, $s[1] * $total],
            $this->covered?->forShape($pts) ?? [],
        );
        $steep = self::steepestWindow($pts, $elev, $cum, $total, $skip);

        return [
            'length' => $total,
            'gain' => $gain,
            /* Foot and summit altitudes from the same elevation array. */
            'footEle' => round($elev[0]),
            'summitEle' => round($elev[$si]),
            'avgGradient' => self::fmt(self::avgGradient($pts, $elev, $cum, $total), 1),
            /* Guard, not a filter: 30% is above any real sustained 250 m. */
            'maxGradient' => self::fmt(min(30.0, max(0.0, $steep['g'])), 0),
            /* Window width travels with the figure (docs/specs/climb-elevation.md §5). */
            'steepWindowM' => self::MAX_WINDOW_M,
            'grad' => self::bars($pts, $elev, $cum, $total, self::binWidthFor($total)),
            /* Colour bands span the climb only (docs/specs/climb-elevation.md §4a). */
            'lineGrad' => self::lineGradients($pts, $elev, $cum, $total),
            'steep' => ['at' => $steep['at'], 'pct' => self::fmt(min(35.0, max(0.0, $steep['g'])), 0), 'manual' => false],
            'demSource' => $read['source'],
            // Bin the bars are drawn at.
            'binM' => self::binWidthFor($total),
            'reversed' => false,
            'overshootM' => $drop >= self::OVERSHOOT_DROP_M ? $tail : 0.0,
            'overshootDropM' => $drop,
            // Hand-placed marker keeps its position; only the number is re-measured (docs/specs/climb-elevation.md §5).
            'sustainedAtSteep' => null === $steepAt
                ? null
                : self::fmt(min(35.0, max(0.0, self::sustainedAt($pts, $elev, $cum, $total, $steepAt))), 0),
        ];
    }

    /**
     * Ascent-only average over ~100 m bins (docs/specs/climb-elevation.md §4b).
     *
     * @param non-empty-list<array{0: float, 1: float}> $pts
     * @param non-empty-list<float>                     $elev
     * @param non-empty-list<float>                     $cum
     */
    private static function avgGradient(array $pts, array $elev, array $cum, float $total): float
    {
        $bins = max(1, (int) round($total / self::AVG_BIN_M));
        $step = $total / $bins;
        $ascent = 0.0;
        $prev = self::at($pts, $elev, $cum, 0.0)['elev'];
        for ($b = 1; $b <= $bins; ++$b) {
            $here = self::at($pts, $elev, $cum, $b * $step)['elev'];
            if ($here > $prev) {
                $ascent += $here - $prev;
            }
            $prev = $here;
        }

        return $total > 0 ? ($ascent / $total) * 100 : 0.0;
    }

    /**
     * @param non-empty-list<array{0: float, 1: float}> $pts
     * @param non-empty-list<float>                     $elev
     * @param non-empty-list<float>                     $cum
     * @param list<array{0: float, 1: float}>           $skip covered [startM, endM]; overlapping windows are skipped
     *
     * @return array{g: float, at: array{0: float, 1: float}}
     */
    private static function steepestWindow(array $pts, array $elev, array $cum, float $total, array $skip = []): array
    {
        $win = min((float) self::MAX_WINDOW_M, $total);
        $best = 0.0;
        $at = $pts[0];
        /* Slide at a fixed step, not vertex to vertex. */
        $step = max(5.0, $win / 10);
        /** @var list<array{g: float, at: array{0: float, 1: float}}> $seen */
        $seen = [];
        for ($start = 0.0; $start <= $total - $win + 0.001; $start += $step) {
            $end = $start + $win;
            foreach ($skip as [$from, $to]) {
                if ($start < $to && $end > $from) {
                    continue 2;   // the window straddles cover: the DEM is reading rock
                }
            }
            $g = ((self::at($pts, $elev, $cum, $end)['elev'] - self::at($pts, $elev, $cum, $start)['elev']) / $win) * 100;
            $seen[] = ['g' => $g, 'at' => self::at($pts, $elev, $cum, ($start + $end) / 2)['coord']];
        }
        if ([] === $seen && [] !== $skip) {
            // Mostly tunnel: measure it all rather than publish nothing.
            return self::steepestWindow($pts, $elev, $cum, $total);
        }
        if ([] !== $seen) {
            // Marker points at the published percentile window, not the discarded spike.
            usort($seen, static fn (array $a, array $b): int => $a['g'] <=> $b['g']);
            $pick = $seen[min(\count($seen) - 1, (int) floor(\count($seen) * self::STEEPEST_PERCENTILE))];
            $best = $pick['g'];
            $at = $pick['at'];
        }
        // Shorter than one window: the climb itself is the steepest stretch.
        if (0.0 === $best && $total > 0) {
            $best = ((self::at($pts, $elev, $cum, $total)['elev'] - $elev[0]) / $total) * 100;
            $at = self::at($pts, $elev, $cum, $total / 2)['coord'];
        }

        return ['g' => $best, 'at' => $at];
    }

    /**
     * Per-position gradients for colouring the map line, distinct from chart bars.
     *
     * @param non-empty-list<array{0: float, 1: float}> $pts
     * @param non-empty-list<float>                     $elev
     * @param non-empty-list<float>                     $cum
     *
     * @return list<int>
     */
    private static function lineGradients(array $pts, array $elev, array $cum, float $span): array
    {
        $step = max(25.0, $span / 120);     // ~120 bands is plenty for a smooth line
        $out = [];
        for ($d = 0.0; $d < $span; $d += $step) {
            // Measure at the band's centre distance, not the nearest sample.
            $out[] = max(-35, min(35, (int) round(
                self::sustainedAtDistance($pts, $elev, $cum, $span, $d + $step / 2),
            )));
        }

        return [] === $out ? [0] : $out;
    }

    /** Narrowest bin on the ladder that keeps the chart under MAX_BARS. */
    public static function binWidthFor(float $total): int
    {
        foreach (self::BIN_LADDER as $w) {
            if (ceil($total / $w) <= self::MAX_BARS) {
                return $w;
            }
        }

        return self::BIN_LADDER[\count(self::BIN_LADDER) - 1];
    }

    /**
     * @param non-empty-list<array{0: float, 1: float}> $pts
     * @param non-empty-list<float>                     $elev
     * @param non-empty-list<float>                     $cum
     *
     * @return list<int>
     */
    private static function bars(array $pts, array $elev, array $cum, float $total, int $binM): array
    {
        $out = [];
        $n = max(1, (int) ceil($total / $binM));
        for ($b = 0; $b < $n; ++$b) {
            $from = $b * $binM;
            $to = min($total, $from + $binM);
            $w = $to - $from;
            if ($w <= 0) {
                break;
            }
            $s = self::at($pts, $elev, $cum, $from)['elev'];
            $e = self::at($pts, $elev, $cum, $to)['elev'];
            $out[] = max(-35, min(35, (int) round((($e - $s) / $w) * 100)));
        }

        return $out;
    }

    /**
     * Elevation samples with true distance along the full-resolution road.
     *
     * @param non-empty-list<array{0: float, 1: float}> $coords
     *
     * @return array{pts: non-empty-list<array{0: float, 1: float}>, cum: non-empty-list<float>}
     */
    private static function sampleWithDistance(array $coords, int $max): array
    {
        $full = self::cumulative($coords);
        $n = \count($coords);
        if ($n <= $max) {
            return ['pts' => $coords, 'cum' => $full];
        }
        $step = ($n - 1) / ($max - 1);
        $pts = [];
        $cum = [];
        for ($i = 0; $i < $max; ++$i) {
            $idx = (int) round($i * $step);
            $pts[] = $coords[$idx];
            $cum[] = $full[$idx];
        }

        return ['pts' => $pts, 'cum' => $cum];
    }

    /**
     * @param non-empty-list<array{0: float, 1: float}> $pts
     *
     * @return list<float>
     */
    private static function cumulative(array $pts): array
    {
        $d = [0.0];
        for ($i = 1, $n = \count($pts); $i < $n; ++$i) {
            $d[] = $d[$i - 1] + self::haversine($pts[$i - 1], $pts[$i]);
        }

        return $d;
    }

    /**
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     */
    private static function haversine(array $a, array $b): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($b[0] - $a[0]);
        $dLng = deg2rad($b[1] - $a[1]);
        $s = \sin($dLat / 2) ** 2 + \cos(deg2rad($a[0])) * \cos(deg2rad($b[0])) * \sin($dLng / 2) ** 2;

        return $r * 2 * \atan2(\sqrt($s), \sqrt(1 - $s));
    }

    /**
     * Elevation and coordinate at a given along-route distance.
     *
     * @param non-empty-list<array{0: float, 1: float}> $pts
     * @param non-empty-list<float>                     $elev
     * @param non-empty-list<float>                     $cum
     *
     * @return array{elev: float, coord: array{0: float, 1: float}}
     */
    private static function at(array $pts, array $elev, array $cum, float $target): array
    {
        $n = \count($cum);
        if ($target <= $cum[0]) {
            return ['elev' => $elev[0], 'coord' => $pts[0]];
        }
        if ($target >= $cum[$n - 1]) {
            return ['elev' => $elev[$n - 1], 'coord' => $pts[$n - 1]];
        }
        for ($i = 1; $i < $n; ++$i) {
            if ($cum[$i] >= $target) {
                $d0 = $cum[$i - 1];
                $d1 = $cum[$i];
                $t = $d1 > $d0 ? ($target - $d0) / ($d1 - $d0) : 0.0;

                return [
                    'elev' => $elev[$i - 1] + $t * ($elev[$i] - $elev[$i - 1]),
                    'coord' => [
                        $pts[$i - 1][0] + $t * ($pts[$i][0] - $pts[$i - 1][0]),
                        $pts[$i - 1][1] + $t * ($pts[$i][1] - $pts[$i - 1][1]),
                    ],
                ];
            }
        }

        return ['elev' => $elev[$n - 1], 'coord' => $pts[$n - 1]];
    }

    /**
     * Sustained gradient at a coordinate, over the same window as the maximum.
     *
     * @param non-empty-list<array{0: float, 1: float}> $pts
     * @param non-empty-list<float>                     $elev
     * @param non-empty-list<float>                     $cum
     * @param array{0: float, 1: float}                 $coord
     */
    private static function sustainedAt(array $pts, array $elev, array $cum, float $total, array $coord): float
    {
        $bestI = 0;
        $bestD = \INF;
        foreach ($pts as $i => $p) {
            $d = ($p[0] - $coord[0]) ** 2 + ($p[1] - $coord[1]) ** 2;   // squared degrees: ordering only
            if ($d < $bestD) {
                $bestD = $d;
                $bestI = $i;
            }
        }

        return self::sustainedAtDistance($pts, $elev, $cum, $total, $cum[$bestI]);
    }

    /**
     * Sustained gradient over a window centred on a distance along the line.
     *
     * @param non-empty-list<array{0: float, 1: float}> $pts
     * @param non-empty-list<float>                     $elev
     * @param non-empty-list<float>                     $cum
     */
    private static function sustainedAtDistance(array $pts, array $elev, array $cum, float $total, float $centre): float
    {
        $win = min((float) self::MAX_WINDOW_M, $total);
        $start = max(0.0, min($centre - $win / 2, $total - $win));
        $end = min($total, $start + $win);
        $d = $end - $start;

        return $d > 0
            ? ((self::at($pts, $elev, $cum, $end)['elev'] - self::at($pts, $elev, $cum, $start)['elev']) / $d) * 100
            : 0.0;
    }

    private static function fmt(float $v, int $dp): string
    {
        return number_format($v, $dp, '.', '').'%';
    }
}
