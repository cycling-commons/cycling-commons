<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Elevation;

/**
 * A planned leg's heights over its bridges and through its tunnels (docs/specs/route-planner.md section 4.4). The
 * elevation model is a surface model without decks: on a bridge it hits the deck in places and the water or the road
 * below in others, through a tunnel it reads the hill above. Measured on dev: at the Houtribsluizen the dike at 6 to
 * 7 m and the sluice bridge read as 0 to 1 m, so the ride "climbed" 6 m back up the bridge's far end; on the
 * Zuiderzeeweg bridge over the Amsterdam-Rijnkanaal, 1.1 km long, the deck read up to 8 m and the water 0 m.
 *
 * Where the engine says the leg is on a bridge (LegSurfaces' `bridgeRuns`), the deck runs along the highest of the
 * heights, from the last sample before the bridge to the first after it: a deck never sags below the straight line
 * between two points of it, so the dips are the water and the hits are the deck. A point a deck could not reach,
 * steeper than MAX_DECK_GRADE from its neighbours on that line, is left out: a pylon or a mast. In a tunnel
 * (`tunnelRuns`) the heights run straight from the sample before it to the sample after.
 *
 * A deck whose top stands DECK_COUNT_M or more over its lower end is a climb the rider rides: its two ends and its
 * top are named (`deckAt`), and the figures always count them (plan-model.js cleanHeights()). Without that, the
 * Zuiderzeeweg bridge's 4.8 m over the last height that counted fell under the noise rule's 5 m and read as flat.
 *
 * @api
 */
final class BridgeHeights
{
    /**
     * Each span is widened this much on both sides, metres: one sample of the profile (ElevationClient's
     * PROFILE_RESAMPLE_M), as the engine's edges and its height samples do not line up to the metre.
     */
    public const float WIDEN_M = 30.0;

    /**
     * The steepest a deck runs between two of its points, as a share. Ramps reach 10 to 13 % between samples 30 m
     * apart in the model, which blurs the ends; a pylon or a mast stands far above that.
     */
    public const float MAX_DECK_GRADE = 0.15;

    /** A deck's top this far over its lower end makes its ends and top count: plan-model.js HEIGHT_NOISE_M. */
    public const float DECK_COUNT_M = 5.0;

    /**
     * @param list<array{0: float, 1: float}>|null $heights [metres along, elevation], as ElevationClient::profile() answers
     * @param list<array{0: int, 1: int}>          $bridges [from, to] metres along the leg's edges
     * @param list<array{0: int, 1: int}>          $tunnels [from, to] metres along the leg's edges
     * @param float                                $legM    the edges' length: the spans are scaled from it to the profile's
     * @param list<float>|null                     $deckAt  set to the metres along the profile of a high deck's ends and top
     *
     * @param-out list<float> $deckAt
     *
     * @return list<array{0: float, 1: float}>|null
     */
    public static function level(?array $heights, array $bridges, array $tunnels, float $legM, ?array &$deckAt = null): ?array
    {
        $deckAt = [];
        if (null === $heights || \count($heights) < 2 || ([] === $bridges && [] === $tunnels)) {
            return $heights;
        }
        $out = $heights;
        $d0 = $heights[0][0];
        $dN = $heights[\count($heights) - 1][0];
        foreach ([[$bridges, true], [$tunnels, false]] as [$spans, $deck]) {
            foreach (self::widened($d0, $dN, $spans, $legM) as [$a, $b]) {
                [$out, $at] = self::lay($out, $a, $b, $deck);
                $deckAt = [...$deckAt, ...$at];
            }
        }

        return $out;
    }

    /**
     * The spans in the profile's metres, from $d0 to $dN, widened by WIDEN_M, overlapping ones merged.
     *
     * @param list<array{0: int, 1: int}> $spans
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function widened(float $d0, float $dN, array $spans, float $legM): array
    {
        $scale = $legM > 0.0 ? ($dN - $d0) / $legM : 1.0;
        $wide = [];
        foreach ($spans as [$from, $to]) {
            $a = $d0 + $from * $scale - self::WIDEN_M;
            $b = $d0 + $to * $scale + self::WIDEN_M;
            $last = array_key_last($wide);
            if (null !== $last && $a <= $wide[$last][1]) {
                $wide[$last][1] = max($wide[$last][1], $b);
            } else {
                $wide[] = [$a, $b];
            }
        }

        return $wide;
    }

    /**
     * The samples strictly between $a and $b laid on the deck (the highest heights) or, for a tunnel, on the straight
     * line, from the last sample before $a to the first after $b. A tunnel at the leg's start or end takes the height
     * beside it. A bridge there has one end on land: its deck rises along its own points to the highest, a pylon left
     * out, and stays at that height to the leg's end (owner 2026-10-26, a leg ending on the Zuiderzeeweg bridge read
     * flat), as the water under the open end is no deck.
     *
     * @param list<array{0: float, 1: float}> $heights
     *
     * @return array{0: list<array{0: float, 1: float}>, 1: list<float>} the heights, and a high deck's ends and top
     */
    private static function lay(array $heights, float $a, float $b, bool $deck): array
    {
        $before = null;
        $after = null;
        foreach ($heights as $i => [$d]) {
            if ($d < $a) {
                $before = $i;
            } elseif ($d > $b) {
                $after = $i;
                break;
            }
        }
        if (null === $before && null === $after) {
            return [$heights, []];
        }
        $first = null === $before ? 0 : $before + 1;
        $stop = null === $after ? \count($heights) : $after;
        if ($deck && (null === $before || null === $after)) {
            return self::layOpen($heights, $first, $stop, $before, $after);
        }
        if (null === $before || null === $after) {
            $h = $heights[$before ?? $after][1];
            for ($i = $first; $i < $stop; ++$i) {
                $heights[$i][1] = $h;
            }

            return [$heights, []];
        }
        // The points the line runs through: both ends, and on a bridge the deck's own.
        $through = $deck ? self::deckPoints(\array_slice($heights, $before, $after - $before + 1)) : [$heights[$before], $heights[$after]];
        $deckAt = [];
        if ($deck) {
            $top = array_reduce($through, static fn (array $best, array $p): array => $p[1] > $best[1] ? $p : $best, $through[0]);
            if ($top[1] - min($heights[$before][1], $heights[$after][1]) >= self::DECK_COUNT_M) {
                $deckAt = [$heights[$before][0], $top[0], $heights[$after][0]];
            }
        }
        $k = 0;
        for ($i = $first; $i < $stop; ++$i) {
            $d = $heights[$i][0];
            while ($through[$k + 1][0] < $d) {
                ++$k;
            }
            [$d1, $h1] = $through[$k];
            [$d2, $h2] = $through[$k + 1];
            $heights[$i][1] = $d2 > $d1 ? $h1 + ($h2 - $h1) * ($d - $d1) / ($d2 - $d1) : $h1;
        }

        return [$heights, $deckAt];
    }

    /**
     * A bridge at the leg's start or end: the open end stands at the highest point of the deck, found without it, and
     * the deck runs along the upper hull from the land end to it.
     *
     * @param list<array{0: float, 1: float}> $heights
     *
     * @return array{0: list<array{0: float, 1: float}>, 1: list<float>}
     */
    private static function layOpen(array $heights, int $first, int $stop, ?int $before, ?int $after): array
    {
        $land = $before ?? $after;
        if (null === $land || $stop - $first < 1) {
            return [$heights, []];
        }
        $span = \array_slice($heights, $first, $stop - $first);
        $known = null === $before ? [...$span, $heights[$land]] : [$heights[$land], ...$span];
        $top = max(array_column(self::deckPoints($known), 1));
        // The open end, at the deck's top, in place of the sample there.
        if (null === $before) {
            $span[0] = [$span[0][0], $top];
            $points = [...$span, $heights[$land]];
        } else {
            $end = array_key_last($span);
            $span[$end] = [$span[$end][0], $top];
            $points = [$heights[$land], ...$span];
        }
        $through = self::deckPoints($points);
        $k = 0;
        for ($i = $first; $i < $stop; ++$i) {
            $d = $heights[$i][0];
            while (isset($through[$k + 1]) && $through[$k + 1][0] < $d) {
                ++$k;
            }
            [$d1, $h1] = $through[$k];
            [$d2, $h2] = $through[$k + 1] ?? $through[$k];
            $heights[$i][1] = $d2 > $d1 ? $h1 + ($h2 - $h1) * ($d - $d1) / ($d2 - $d1) : $h1;
        }
        $peak = array_reduce($through, static fn (array $best, array $p): array => $p[1] > $best[1] ? $p : $best, $through[0]);
        $deckAt = $peak[1] - $heights[$land][1] >= self::DECK_COUNT_M ? [$heights[$land][0], $peak[0]] : [];
        sort($deckAt);

        return [$heights, $deckAt];
    }

    /**
     * The upper hull of the samples, first and last kept: the highest line over them that never bends up. A point
     * whose hull side is steeper than MAX_DECK_GRADE is dropped and the hull drawn again.
     *
     * @param list<array{0: float, 1: float}> $points [metres along, elevation], in order, at least two
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function deckPoints(array $points): array
    {
        while (true) {
            $hull = [];
            foreach ($points as $p) {
                while (\count($hull) >= 2) {
                    [$ox, $oy] = $hull[\count($hull) - 2];
                    [$mx, $my] = $hull[\count($hull) - 1];
                    // Drop the middle point while it lies on or under the line from the one before to this one.
                    if (($mx - $ox) * ($p[1] - $oy) - ($my - $oy) * ($p[0] - $ox) >= 0) {
                        array_pop($hull);
                    } else {
                        break;
                    }
                }
                $hull[] = $p;
            }
            $spike = null;
            for ($j = 1, $n = \count($hull); $j < $n; ++$j) {
                [$d1, $h1] = $hull[$j - 1];
                [$d2, $h2] = $hull[$j];
                if ($d2 > $d1 && abs($h2 - $h1) / ($d2 - $d1) > self::MAX_DECK_GRADE) {
                    // The higher end, unless it is one of the bridge's ends: those stay.
                    $high = $h2 > $h1 ? $j : $j - 1;
                    $spike = 0 === $high || $n - 1 === $high ? ($high === $j ? $j - 1 : $j) : $high;
                    if (0 === $spike || $n - 1 === $spike) {
                        $spike = null;
                        continue;
                    }
                    break;
                }
            }
            if (null === $spike) {
                return $hull;
            }
            $drop = $hull[$spike];
            $points = array_values(array_filter($points, static fn (array $p): bool => $p !== $drop));
        }
    }
}
