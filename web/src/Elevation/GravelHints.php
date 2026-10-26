<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Elevation;

use App\Catalog\BikeType;

/**
 * Which way a gravel or mountain bike rides a leg, when the planner offers ways through gravel (route-planner.md
 * section 4.2b). The engine rides the fastest way, and for every bike that is asphalt wherever asphalt is as quick:
 * its bicycle types tolerate rough ground, none prefers it. The planner finds gravel near the leg in the
 * road-surface tiles and asks for the leg through points on it; this class keeps the best of those answers, or
 * none.
 *
 * A way through gravel is kept only when it is a fair trade: at most MAX_GROWTH times the engine's length, at least
 * MIN_GAIN_M (or MIN_GAIN_SHARE of the leg) more unpaved road, and more unpaved road by at least EXTRA_SHARE of the
 * length it adds: a way to gravel mostly needs a paved link to reach it. Among those, the one with the most unpaved
 * road gained for the length it adds. A way that rides a stretch twice is never kept: a point at the end of a
 * dead-end track sends the leg down it and back up the same road (the owner's ride near Barneveld, 2026-10-25).
 *
 * @api
 */
final class GravelHints
{
    public const float MAX_GROWTH = 1.25;
    public const int MIN_GAIN_M = 500;
    public const float MIN_GAIN_SHARE = 0.05;
    public const float EXTRA_SHARE = 0.5;
    /** Metres ridden twice that make a way an out-and-back. */
    public const int MAX_TWICE_M = 150;
    /** Ways through gravel asked per leg, and points per way. */
    public const int MAX_CANDIDATES = 6;
    public const int MAX_VIAS = 6;

    /** The bikes that seek out gravel; every other keeps the engine's way. */
    public static function seeks(BikeType $bike): bool
    {
        return BikeType::Gravel === $bike || BikeType::Mtb === $bike;
    }

    /**
     * Unpaved metres in a leg's surfaces that the bike seeks: gravel and rough (LegSurfaces' classes), less the sand
     * inside rough for a gravel bike, as heavy going on narrow tyres is no gain (the Ederheide, 2026-10-25).
     *
     * @param array<string, int|float>|null $surfaces
     */
    public static function unpavedM(?array $surfaces, ?BikeType $bike = null): float
    {
        if (!\is_array($surfaces)) {
            return 0.0;
        }
        $sand = BikeType::Gravel === $bike ? (float) ($surfaces['sand'] ?? 0) : 0.0;

        return max(0.0, (float) ($surfaces['gravel'] ?? 0) + (float) ($surfaces['rough'] ?? 0) - $sand);
    }

    /**
     * The index of the way to ride, or null to keep the engine's.
     *
     * @param array{distanceM: float, unpavedM: float}                            $base
     * @param list<array{distanceM: float, unpavedM: float, twiceM?: float}|null> $ways one per way through gravel, null when it has no road
     */
    public static function choose(array $base, array $ways): ?int
    {
        $best = null;
        $bestScore = 0.0;
        $minGain = max((float) self::MIN_GAIN_M, $base['distanceM'] * self::MIN_GAIN_SHARE);
        foreach ($ways as $i => $way) {
            if (null === $way || $way['distanceM'] > $base['distanceM'] * self::MAX_GROWTH || ($way['twiceM'] ?? 0.0) > self::MAX_TWICE_M) {
                continue;
            }
            $gain = $way['unpavedM'] - $base['unpavedM'];
            $extra = max(0.0, $way['distanceM'] - $base['distanceM']);
            if ($gain < $minGain || $gain < $extra * self::EXTRA_SHARE) {
                continue;
            }
            $score = $gain - $extra / 2;
            if (null === $best || $score > $bestScore) {
                $best = $i;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Metres of a line that ride a stretch it already rode: walked in 10 m steps over a grid of 20 m cells, a cell
     * entered again after the line left it for more than 70 m. A road crossed once more at a junction counts a
     * cell, about 20 m; riding down a track and back counts its length.
     *
     * @param list<array{0: float, 1: float}> $coords [lng, lat]
     */
    public static function twiceM(array $coords): float
    {
        if (\count($coords) < 2) {
            return 0.0;
        }
        $kx = 111320.0 * cos($coords[0][1] * M_PI / 180.0);
        $seen = [];
        $last = null;
        $step = 0;
        $twice = 0.0;
        $again = false;
        for ($i = 1, $n = \count($coords); $i < $n; ++$i) {
            [$ax, $ay] = $coords[$i - 1];
            [$bx, $by] = $coords[$i];
            $len = hypot(($bx - $ax) * $kx, ($by - $ay) * 111320.0);
            $parts = max(1, (int) round($len / 10.0));
            for ($k = 0; $k < $parts; ++$k) {
                $x = $ax + ($bx - $ax) * $k / $parts;
                $y = $ay + ($by - $ay) * $k / $parts;
                $cell = (int) floor($x * $kx / 20.0).','.(int) floor($y * 111320.0 / 20.0);
                ++$step;
                if ($cell !== $last) {
                    $again = isset($seen[$cell]) && $step - $seen[$cell] > 7;
                    $seen[$cell] = $step;
                    $last = $cell;
                }
                if ($again) {
                    $twice += $len / $parts;
                }
            }
        }

        return $twice;
    }
}
