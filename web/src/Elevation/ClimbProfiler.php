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
     * The distance a published "steepest" figure is averaged over.
     *
     * **250 m, not the 100 m climb databases quote, because 100 m is finer than
     * this DEM can answer.** §3a's own rule is that a window is never narrower
     * than about four DEM cells; GLO-30's 30 m cells put that floor at 120 m, so
     * the original 100 m was below the source's resolution from the start. It
     * survived only because the Ardennes seed climbs are short and unroofed.
     *
     * The Alps disproved it. Measured 2026-08-06: Furka published 20% against a
     * real ~10%, and Grimsel, Susten and Klausen all published exactly 35% —
     * which was not a measurement but the clamp below, hiding raw windows as
     * steep as 77%. Two independent ground truths then agreed on this window
     * with {@see STEEPEST_PERCENTILE}: Wallonia's 50 cm LiDAR says Stockeu's
     * steepest is 16.7% and we now read 16.7%; the owner reports Furka at ~10%
     * and we now read 10.3%.
     */
    private const int MAX_WINDOW_M = 250;

    /**
     * Which sliding window is published — the 95th percentile, not the steepest.
     *
     * A maximum is an extreme-value statistic, and on a Digital Surface Model
     * the extreme is essentially always an artifact: an avalanche gallery, a
     * cutting, a rock face beside the carriageway, a canopy edge. The proof is
     * that the raw maximum got WORSE as sampling improved — densifying Furka
     * from 50 m to 20 m moved Grimsel's raw window from 35% to 77%, because
     * finer sampling finds more spikes rather than more road.
     *
     * A high percentile keeps the honest answer and drops the spikes: the
     * published figure is the gradient that 5% of windows exceed, so a genuine
     * sustained ramp still surfaces while a single bad cell cannot. This is the
     * same reasoning that makes the AVERAGE trustworthy on a DSM — errors
     * cancel over many samples — applied to the one figure that never had it.
     */
    private const float STEEPEST_PERCENTILE = 0.95;

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

    public function __construct(
        private readonly ElevationClient $elevation,
        /**
         * Tunnels and galleries, excluded from the steepest-stretch search.
         * Null keeps the pre-2026-08-07 behaviour, which is what the tests that
         * do not care about cover construct.
         */
        private readonly ?CoveredSpans $covered = null,
    ) {
    }

    /**
     * @param list<array{0: float, 1: float}> $route   [lat, lng], foot to summit
     * @param array{0: float, 1: float}|null  $steepAt a marker the rider placed
     *                                                 by hand; its gradient is re-read at that
     *                                                 position rather than the marker being moved (§5)
     *
     * @return array{
     *     length: float, gain: float, avgGradient: string, maxGradient: string,
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
        // Fractions, converted to this line's own metres: the matched geometry
        // is not the shape we sent, so only a proportion survives the round trip.
        $skip = array_map(
            static fn (array $s): array => [$s[0] * $total, $s[1] * $total],
            $this->covered?->forShape($pts) ?? [],
        );
        $steep = self::steepestWindow($pts, $elev, $cum, $total, $skip);

        return [
            'length' => $total,
            'gain' => $gain,
            'avgGradient' => self::fmt(self::avgGradient($pts, $elev, $cum, $total), 1),
            /* The 35% clamp this used to carry was load-bearing and should not
               have been: Grimsel, Susten and Klausen all published exactly 35%,
               which looked like three steep passes and was really one ceiling
               three artifacts had hit. With a percentile over a 250 m window the
               figure is inside the plausible range on its own, so the clamp is
               back to being a guard rather than a filter — 30% is above any real
               road's sustained 250 m and only fires if the estimator itself is
               wrong, which is when we want to see it, not hide it. */
            'maxGradient' => self::fmt(min(30.0, max(0.0, $steep['g'])), 0),
            /* The width the figure is averaged over travels WITH it, so the
               label cannot drift from the measurement (climb-elevation.md §5). */
            'steepWindowM' => self::MAX_WINDOW_M,
            'grad' => self::bars($pts, $elev, $cum, $total, self::binWidthFor($total)),
            /* Spans the CLIMB, and the map must draw only the climb to match.
               These bands are mapped onto `line-progress`, which runs 0..1 over
               whatever geometry is rendered, so the two extents have to be the
               same or every band is stretched along the line — measuring to the
               summit while drawing the whole route pushed the darkest band 145 m
               past the marker (owner-reported 2026-08-05).

               Drawing the climb rather than the whole line is also what makes
               the map agree with everything else published: La Redoute's stored
               contribution runs 361 m past its summit and gently descends, so
               the map showed 2.44 km with a blue tail beside a chart and a
               length that both said 2.1 km. The overshoot is kept in `route` —
               it is a rider's contribution, and §4a warns rather than
               discarding — but it is not part of the climb. */
            'lineGrad' => self::lineGradients($pts, $elev, $cum, $total),
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
     * @param list<array{0: float, 1: float}> $skip covered [startM, endM] spans;
     *                                              a window overlapping one is not a candidate, because over a
     *                                              tunnel the DEM is reading the mountain and not the road
     *
     * @return array{g: float, at: array{0: float, 1: float}}
     */
    private static function steepestWindow(array $pts, array $elev, array $cum, float $total, array $skip = []): array
    {
        $win = min((float) self::MAX_WINDOW_M, $total);
        $best = 0.0;
        $at = $pts[0];
        /* Slide at a FIXED step, not from vertex to vertex.

           Starting a window at each route vertex sounds equivalent and is not:
           a routing engine places vertices where the road bends, so a straight
           gives you almost none. On Côte d'Ereffe that left a gap with no vertex
           near 660 m, the 17.7% window there was never evaluated, and the marker
           landed on a 17% stretch 634 m away — visibly off the darkest part of
           the line (owner-reported 2026-08-05). A fixed step also makes this
           agree with lineGradients(), which has always stepped by distance. */
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
            // Every window straddled cover — a climb that is mostly tunnel. Fall
            // back to measuring it all rather than publishing nothing: the
            // figure is then no worse than it was before cover was considered.
            return self::steepestWindow($pts, $elev, $cum, $total);
        }
        if ([] !== $seen) {
            // Publish the STEEPEST_PERCENTILE window rather than the steepest
            // one. The marker moves with the figure — it must point at the
            // stretch we publish, not at the artifact we just discarded, or the
            // map disagrees with the number beside it.
            usort($seen, static fn (array $a, array $b): int => $a['g'] <=> $b['g']);
            $pick = $seen[(int) min(\count($seen) - 1, (int) floor(\count($seen) * self::STEEPEST_PERCENTILE))];
            $best = $pick['g'];
            $at = $pick['at'];
        }
        // A climb shorter than one window still has a steepest stretch: itself.
        if (0.0 === $best && $total > 0) {
            $best = ((self::at($pts, $elev, $cum, $total)['elev'] - $elev[0]) / $total) * 100;
            $at = self::at($pts, $elev, $cum, $total / 2)['coord'];
        }

        return ['g' => $best, 'at' => $at];
    }

    /**
     * Per-position gradients for COLOURING THE MAP LINE, as distinct from the
     * chart's bars.
     *
     * The two must not share a series, and a marker that landed off the darkest
     * stretch is how we found out (owner-reported 2026-08-05). The steepest-ramp
     * marker slides its window to ANY offset, while the bars sit at fixed
     * boundaries, so on La Redoute the marker reads 17% at 970 m while the
     * steepest bar is 15% at 1100 m — a different stretch of road. Both figures
     * are right; they simply answer "how steep is the worst 100 m" and "how
     * steep is this particular 100 m".
     *
     * Colouring the line by the sustained gradient CENTRED at each point makes
     * the darkest part of the line the steepest part of the road by
     * construction, which is where the marker is. The bars keep their fixed
     * bins, because a chart needs comparable columns.
     *
     * `$span` is the length of the DRAWN LINE, which is not always the length of
     * the climb: a line running past its summit is longer. These bands are
     * mapped onto `line-progress`, which runs 0..1 over the rendered geometry,
     * so measuring over anything shorter stretches every band along the line.
     * Covering the full line also means a trailing descent is coloured as one
     * rather than inheriting the last climbing band.
     *
     * @param list<array{0: float, 1: float}> $pts
     * @param list<float>                     $elev
     * @param list<float>                     $cum
     *
     * @return list<int>
     */
    private static function lineGradients(array $pts, array $elev, array $cum, float $span): array
    {
        $step = max(25.0, $span / 120);     // ~120 bands is plenty for a smooth line
        $out = [];
        for ($d = 0.0; $d < $span; $d += $step) {
            // Measured at the band's own centre DISTANCE. Going via a
            // coordinate would snap to the nearest of the 200 samples first,
            // and that quantisation is enough to under-read the peak: La
            // Redoute's darkest band came out 15% beside a marker reading 17%,
            // which is a whole colour step.
            $out[] = (int) max(-35, min(35, (int) round(
                self::sustainedAtDistance($pts, $elev, $cum, $span, $d + $step / 2),
            )));
        }

        return [] === $out ? [0] : $out;
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

        return self::sustainedAtDistance($pts, $elev, $cum, $total, $cum[$bestI]);
    }

    /**
     * The sustained gradient over a window centred on a DISTANCE along the line.
     *
     * The distance-based form is the real one; sustainedAt(coord) resolves a
     * coordinate to a distance and defers here. Taking a distance directly is
     * what lets the line's colour bands be measured at their own centres rather
     * than at whichever sample happens to be nearest.
     *
     * @param list<array{0: float, 1: float}> $pts
     * @param list<float>                     $elev
     * @param list<float>                     $cum
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
