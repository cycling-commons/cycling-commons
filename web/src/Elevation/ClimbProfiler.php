<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Elevation;

/**
 * Measures a climb from its drawn line: length, gain, average and maximum
 * gradient, the display profile, and where the steepest ramp is.
 *
 * This is the ONE implementation. It used to live in the browser
 * (`climb-elevation.js`), which meant the server stored whatever values a
 * client sent it — shape-checked but never recomputed — and a catalogue-wide
 * sweep was impossible because the maths was not where the data is. Moving it
 * here makes the preview a rider sees and the value that gets stored the same
 * computation by construction, and lets a command re-measure every climb.
 *
 * @see docs/specs/climb-elevation.md §3, §4, §5
 *
 * @api Used by ElevationController and App\Command\RecomputeClimbProfilesCommand.
 */
final class ClimbProfiler
{
    /**
     * Bin widths the display profile may use, narrowest first.
     *
     * The profile used to be **eleven equal slices of whatever the climb was**,
     * which meant a bar was a different distance on every climb: ~220 m on a
     * 2.4 km climb, 1.5 km on Hockai. Two climbs' charts could not be compared,
     * and a short steep ramp was averaged flat on any long climb. Fixing the
     * bin to a real distance fixes both — and it is what makes the caption's
     * "per 100 m" mean something (owner, 2026-08-05).
     */
    private const array BIN_LADDER = [100, 150, 200, 250, 500, 1000, 2000];

    /**
     * Most bars a chart may draw. Beyond this the profile stops being a shape
     * and becomes texture — 17 km at 100 m would be 169 of them.
     */
    private const int MAX_BARS = 25;

    /**
     * The distance a published "max gradient" is averaged over.
     *
     * 100 m because that is what climb databases report, so our figure is
     * comparable with the sites a rider checks us against. A longer window
     * reads systematically gentler than every other source for the same road.
     */
    private const int MAX_WINDOW_M = 100;

    /**
     * Bin width for the ascent-only average — see avgGradient() for why binning
     * first matters. Deliberately fixed rather than following the display
     * ladder: the published average must not change because a climb got long
     * enough to redraw with wider bars.
     */
    private const int AVG_BIN_M = 100;

    /** Samples requested along the line. ~20 m spacing on a 4 km climb (§3c). */
    private const int SAMPLES = 200;

    /**
     * Metres a trailing stretch must LOSE before it counts as running past the
     * summit.
     *
     * Not a distance test, which is what an earlier version got wrong. Many
     * climbs finish on a plateau — Roche-aux-Faucons is flat for its last 73 m
     * — and on flat ground the "highest point" is decided by DEM noise rather
     * than by the road, so its position wanders and a distance test flags a
     * route that is perfectly correct. That climb's tail is 130 m long and
     * drops 1 m; La Redoute's is 361 m and drops 10 m. Only the second is a
     * descent (owner-reported 2026-08-05).
     */
    private const float OVERSHOOT_DROP_M = 8.0;

    public function __construct(private readonly ElevationClient $elevation)
    {
    }

    /**
     * @param list<array{0: float, 1: float}> $route   [lat, lng], foot to summit
     * @param array{0: float, 1: float}|null  $steepAt a marker the rider placed
     *                                                 by hand; its gradient is re-read at that
     *                                                 position rather than the marker being moved (§5)
     *
     * @return array{
     *     length: float, gain: float, avgGradient: string, maxGradient: string,
     *     grad: list<int>, steep: array{at: array{0: float, 1: float}, pct: string, manual: bool},
     *     demSource: string, binM: int, reversed: bool, overshootM: float, overshootDropM: float
     * }|null null when elevation could not be established (§2d)
     */
    public function profile(array $route, ?array $steepAt = null): ?array
    {
        if (\count($route) < 2) {
            return null;
        }
        $pts = self::sample($route, self::SAMPLES);
        $read = $this->elevation->heights($pts);
        if (null === $read) {
            return null;
        }
        $elev = $read['elevations'];
        $cum = self::cumulative($pts);

        // The last index at the maximum, not the first: on a plateau the
        // earliest and latest high points can be hundreds of metres apart, and
        // the climb plainly does not end at the start of the flat.
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
            // A descending line measures 0 m over 0 m at 0%, which is three
            // values that look unremarkable in a database column. Refuse
            // instead (§4a).
            return null;
        }

        $gain = $elev[$si] - $elev[0];
        $steep = self::steepestWindow($pts, $elev, $cum, $total);

        return [
            'length' => $total,
            'gain' => $gain,
            'avgGradient' => self::fmt(self::avgGradient($pts, $elev, $cum, $total), 1),
            'maxGradient' => self::fmt(min(35.0, max(0.0, $steep['g'])), 0),
            'grad' => self::bars($pts, $elev, $cum, $total, self::binWidthFor($total)),
            'steep' => ['at' => $steep['at'], 'pct' => self::fmt(min(35.0, max(0.0, $steep['g'])), 0), 'manual' => false],
            'demSource' => $read['source'],
            // The bin the BARS are drawn at, so the chart can label itself.
            'binM' => self::binWidthFor($total),
            'reversed' => false,
            'overshootM' => $drop >= self::OVERSHOOT_DROP_M ? $tail : 0.0,
            'overshootDropM' => $drop,
            // A hand-placed marker keeps its position; only the number under it
            // is re-measured, over the same window the maximum uses (§5).
            'sustainedAtSteep' => null === $steepAt
                ? null
                : self::fmt(min(35.0, max(0.0, self::sustainedAt($pts, $elev, $cum, $total, $steepAt))), 0),
        ];
    }

    /**
     * Average gradient counting ONLY the parts that go up.
     *
     * A climb with a dip has two defensible averages far apart: net gain over
     * length lets the descent cancel the climbing either side of it, which is
     * not what the rider did. Ascent-only is what climb sites publish.
     *
     * Measured over ~100 m bins rather than raw samples because ascent-only is
     * noise-sensitive by construction — every upward wobble adds and nothing
     * subtracts — so summing raw deltas inflates the figure on exactly the
     * wooded climbs whose readings are least trustworthy.
     *
     * @param list<array{0: float, 1: float}> $pts
     * @param list<float>                     $elev
     * @param list<float>                     $cum
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
     * @param list<array{0: float, 1: float}> $pts
     * @param list<float>                     $elev
     * @param list<float>                     $cum
     *
     * @return array{g: float, at: array{0: float, 1: float}}
     */
    private static function steepestWindow(array $pts, array $elev, array $cum, float $total): array
    {
        $win = min((float) self::MAX_WINDOW_M, $total);
        $best = 0.0;
        $at = $pts[0];
        foreach ($pts as $i => $_) {
            $start = $cum[$i];
            $end = $start + $win;
            if ($end > $total) {
                $end = $total;
                $start = max(0.0, $total - $win);
            }
            $d = $end - $start;
            if ($d <= 0) {
                continue;
            }
            $g = ((self::at($pts, $elev, $cum, $end)['elev'] - self::at($pts, $elev, $cum, $start)['elev']) / $d) * 100;
            if ($g > $best) {
                $best = $g;
                $at = self::at($pts, $elev, $cum, ($start + $end) / 2)['coord'];
            }
        }

        return ['g' => $best, 'at' => $at];
    }

    /**
     * The narrowest bin on the ladder that keeps the chart under MAX_BARS.
     *
     * A 2.4 km climb draws 24 bars of 100 m; past 2.5 km it steps to 150 m, and
     * so on. The last rung is a floor, not a guarantee — a very long route draws
     * more bars rather than being silently truncated.
     */
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
     * @param list<array{0: float, 1: float}> $pts
     * @param list<float>                     $elev
     * @param list<float>                     $cum
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
            $out[] = (int) max(-35, min(35, (int) round((($e - $s) / $w) * 100)));
        }

        return $out;
    }

    /**
     * @param list<array{0: float, 1: float}> $coords
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function sample(array $coords, int $max): array
    {
        $n = \count($coords);
        if ($n <= $max) {
            return $coords;
        }
        $step = ($n - 1) / ($max - 1);
        $out = [];
        for ($i = 0; $i < $max; ++$i) {
            $out[] = $coords[(int) round($i * $step)];
        }

        return $out;
    }

    /**
     * @param list<array{0: float, 1: float}> $pts
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
     * @param list<array{0: float, 1: float}> $pts
     * @param list<float>                     $elev
     * @param list<float>                     $cum
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
     * The sustained gradient AT a coordinate, over the same window the maximum
     * uses. The display bars are the wrong instrument for this: they are equal
     * slices of the WHOLE climb, so extending a climb widens every bin and
     * averages a short ramp flat.
     *
     * @param list<array{0: float, 1: float}> $pts
     * @param list<float>                     $elev
     * @param list<float>                     $cum
     * @param array{0: float, 1: float}       $coord
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
        $win = min((float) self::MAX_WINDOW_M, $total);
        $start = max(0.0, min($cum[$bestI] - $win / 2, $total - $win));
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
