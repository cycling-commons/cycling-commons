<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Elevation;

use App\Elevation\BridgeHeights;
use PHPUnit\Framework\TestCase;

/**
 * The heights of a planned leg over its bridges and through its tunnels (docs/specs/route-planner.md section 4.4):
 * the elevation model has no deck, so on a bridge it reads the water or the road below, and over a tunnel the hill
 * above. Between the heights just before and just after, the deck runs straight.
 */
final class BridgeHeightsTest extends TestCase
{
    /** @return list<array{0: float, 1: float}> */
    private static function profile(float ...$hs): array
    {
        return array_map(static fn (int $i, float $h): array => [$i * 30.0, $h], array_keys($hs), $hs);
    }

    /**
     * The Houtribsluizen on dev (owner 2026-10-26: "Climbing is incorrect"): the dike at 6 to 7 m, the sluice bridge
     * read as the water at 0 to 1 m, so the ride "climbed" 6 m back up the far end of the bridge. The deck lies level.
     */
    public function testALevelBridgeOverWaterStaysLevel(): void
    {
        $heights = self::profile(6, 6, 6, 3, 0, 1, 1, 0, 1, 4, 6, 6);   // 0 to 330 m; the bridge 100 to 260 m
        $deckAt = [];
        $level = BridgeHeights::level($heights, [[100, 260]], [], 330.0, $deckAt);

        self::assertNotNull($level);
        self::assertSame([], $deckAt, 'a level deck: nothing to count');
        self::assertSame([0.0, 30.0, 60.0], array_column(\array_slice($level, 0, 3), 0), 'the metres stay');
        foreach ($level as [$d, $h]) {
            self::assertSame(6.0, $h, "at $d m");
        }
    }

    /** The engine's edges and its height samples measure the line a little differently: the spans are scaled to it. */
    public function testTheSpansAreScaledToTheProfilesLength(): void
    {
        // The edges say 660 m, the profile 330 m: the bridge at 200 to 520 m of the edges is 100 to 260 m here.
        $level = BridgeHeights::level(self::profile(6, 6, 6, 3, 0, 1, 1, 0, 1, 4, 6, 6), [[200, 520]], [], 660.0);

        self::assertNotNull($level);
        self::assertSame([6.0], array_values(array_unique(array_column($level, 1))));
    }

    /**
     * A tunnel under a hill: the model reads the hill. The span is widened by WIDEN_M on both sides, as the edges and
     * the samples do not line up to the metre, so the deck runs from the last height before that to the first after.
     */
    public function testATunnelUnderAHillRunsStraightAndTheRestStays(): void
    {
        $heights = self::profile(10, 11, 12, 30, 40, 30, 14, 15, 16);   // the tunnel 80 to 170 m, widened 50 to 200 m
        $level = BridgeHeights::level($heights, [], [[80, 170]], 240.0);

        self::assertNotNull($level);
        self::assertSame([10.0, 11.0], array_column(\array_slice($level, 0, 2), 1));
        foreach ([2 => 11.667, 3 => 12.333, 4 => 13.0, 5 => 13.667, 6 => 14.333] as $i => $h) {
            self::assertEqualsWithDelta($h, $level[$i][1], 0.001, "sample $i");
        }
        self::assertSame([15.0, 16.0], array_column(\array_slice($level, 7), 1));
    }

    public function testABridgeAtTheStartTakesTheFirstHeightAfterIt(): void
    {
        $level = BridgeHeights::level(self::profile(1, 0, 1, 6, 6), [[0, 40]], [], 120.0);

        self::assertNotNull($level);
        self::assertSame([6.0, 6.0, 6.0, 6.0, 6.0], array_column($level, 1));
    }

    public function testNoBridgeOrNoHeightsLeavesThemAsTheyAre(): void
    {
        $heights = self::profile(1, 2, 3);
        self::assertSame($heights, BridgeHeights::level($heights, [], [], 60.0));
        self::assertNull(BridgeHeights::level(null, [[0, 10]], [], 60.0));
    }

    /**
     * The Zuiderzeeweg bridge over the Amsterdam-Rijnkanaal on dev (owner 2026-10-26: "I think the part inside the
     * marker is over 2%"): 1.1 km of bridge with a hump, the model hitting the deck in places and the water in others.
     * The deck runs along the highest of them, the dips left out; a straight line from end to end lost the hump.
     */
    public function testAHumpedBridgeKeepsItsHumpAndLosesItsDips(): void
    {
        //              before   on the bridge, 60 to 300 m                        after
        $heights = self::profile(2, 2, 4, 8, 6, 0, -1, 0, 2, 5, 6, 2, 2);
        $deckAt = [];
        $level = BridgeHeights::level($heights, [[90, 270]], [], 360.0, $deckAt);

        self::assertNotNull($level);
        // The deck rises 6 m over its lower end: its ends and its top always count (cleanHeights' `keepAt`), or the
        // noise rule, 5 m from the last height that counted, can miss the hump.
        self::assertSame([30.0, 90.0, 330.0], $deckAt);
        self::assertSame([2.0, 2.0], array_column(\array_slice($level, 0, 2), 1), 'before the bridge');
        self::assertSame(8.0, $level[3][1], 'the deck where the model hit it');
        foreach ([4, 5, 6, 7, 8, 9] as $i) {
            self::assertGreaterThan(5.9, $level[$i][1], "no dip at sample $i");
        }
        self::assertSame([2.0, 2.0], array_column(\array_slice($level, 11), 1), 'after the bridge');
    }

    /** A spike on a bridge steeper than a deck can be (MAX_DECK_GRADE), a pylon or a mast, is left out too. */
    public function testASpikeOnABridgeIsLeftOut(): void
    {
        $heights = self::profile(5, 5, 5, 5, 60, 5, 5, 5, 5);
        $level = BridgeHeights::level($heights, [[60, 180]], [], 240.0);

        self::assertNotNull($level);
        self::assertSame([5.0], array_values(array_unique(array_column($level, 1))));
    }

    /**
     * A leg that ends on a bridge (owner 2026-10-26, from Insulindeweg to a point on the Zuiderzeeweg bridge: "only get
     * flat"): no height after it, so the deck rises along its own points to the highest, a pylon left out, and stays
     * there to the end; the water under the end is no deck. The rise counts as a deck's.
     */
    public function testALegEndingOnABridgeKeepsTheDecksRise(): void
    {
        //                         before  the bridge, 60 m to the end
        $heights = self::profile(3, 2, 2, 2, 4, 6, 7, 5, 60, 7, -1, -1);
        $deckAt = [];
        $level = BridgeHeights::level($heights, [[90, 330]], [], 330.0, $deckAt);

        self::assertNotNull($level);
        self::assertSame([3.0, 2.0], array_column(\array_slice($level, 0, 2), 1));
        self::assertSame(7.0, $level[6][1], 'the deck where the model hit it');
        foreach ([7, 8, 9, 10, 11] as $i) {
            self::assertSame(7.0, $level[$i][1], "the deck at its top to the end, sample $i");
        }
        self::assertSame([30.0, 180.0], $deckAt, 'its foot and its top count');
    }
}
