<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Elevation;

use App\Catalog\BikeType;
use App\Elevation\GravelHints;
use PHPUnit\Framework\TestCase;

/**
 * Which way through gravel a gravel or mountain bike rides (route-planner.md section 4.2b): a fair trade or none.
 * The numbers are the ones measured on the dev engine for Amersfoort to Barneveld: the engine's 17.6 km with no
 * unpaved road, four ways through gravel.
 */
final class GravelHintsTest extends TestCase
{
    private const array BASE = ['distanceM' => 17600.0, 'unpavedM' => 0.0];

    public function testTheBestFairTradeIsKept(): void
    {
        $ways = [
            ['distanceM' => 20600.0, 'unpavedM' => 0.0],      // longer, no gravel
            ['distanceM' => 22000.0, 'unpavedM' => 2000.0],   // 4.4 km more for 2.0 km of gravel: under half, not fair
            ['distanceM' => 19000.0, 'unpavedM' => 2200.0],   // fair: 1.4 km more for 2.2 km
            ['distanceM' => 18700.0, 'unpavedM' => 2600.0],   // fairer: 1.1 km more for 2.6 km
        ];
        self::assertSame(3, GravelHints::choose(self::BASE, $ways));
    }

    public function testAWayFarLongerThanTheEnginesIsNeverKept(): void
    {
        self::assertNull(GravelHints::choose(self::BASE, [['distanceM' => 17600.0 * 1.26, 'unpavedM' => 9000.0]]), 'more than 25% longer');
    }

    public function testTooLittleGravelIsNotWorthTheChange(): void
    {
        // 5% of 17.6 km is 880 m: 800 m more is not enough, even at no extra length.
        self::assertNull(GravelHints::choose(self::BASE, [['distanceM' => 17600.0, 'unpavedM' => 800.0]]));
        self::assertSame(0, GravelHints::choose(self::BASE, [['distanceM' => 17600.0, 'unpavedM' => 900.0]]));
    }

    public function testAWayWithNoRoadIsSkipped(): void
    {
        self::assertSame(1, GravelHints::choose(self::BASE, [null, ['distanceM' => 18000.0, 'unpavedM' => 2000.0]]));
        self::assertNull(GravelHints::choose(self::BASE, [null]));
    }

    /** A way to gravel mostly needs a paved link: half the added length in gravel is a fair trade (the owner's ride, 2026-10-25). */
    public function testHalfTheAddedLengthInGravelIsEnough(): void
    {
        $base = ['distanceM' => 20700.0, 'unpavedM' => 2500.0];
        $ways = [
            ['distanceM' => 24600.0, 'unpavedM' => 5900.0],   // 3.9 km more, 3.4 km more gravel
            ['distanceM' => 28000.0, 'unpavedM' => 8200.0],   // more than 25% longer
            ['distanceM' => 24100.0, 'unpavedM' => 4900.0],
            ['distanceM' => 24300.0, 'unpavedM' => 4700.0],
        ];
        self::assertSame(0, GravelHints::choose($base, $ways));
        self::assertNull(GravelHints::choose($base, [['distanceM' => 24100.0, 'unpavedM' => 4100.0]]), '3.4 km more for 1.6 km of gravel: under half');
    }

    public function testTheGainCountsFromWhatTheEnginesWayAlreadyRides(): void
    {
        $base = ['distanceM' => 16700.0, 'unpavedM' => 2400.0];
        self::assertNull(GravelHints::choose($base, [['distanceM' => 16700.0, 'unpavedM' => 2400.0]]), 'the same gravel is no gain');
        self::assertSame(0, GravelHints::choose($base, [['distanceM' => 20700.0, 'unpavedM' => 7200.0]]), '4 km more for 4.8 km more gravel');
    }

    /** Down a dead-end track and back up the same road (the owner's ride near Barneveld, 2026-10-25). */
    public function testAWayThatRidesARoadTwiceIsNeverKept(): void
    {
        $base = ['distanceM' => 20700.0, 'unpavedM' => 2500.0];
        self::assertNull(GravelHints::choose($base, [['distanceM' => 24600.0, 'unpavedM' => 5900.0, 'twiceM' => 900.0]]));
        self::assertSame(0, GravelHints::choose($base, [['distanceM' => 24600.0, 'unpavedM' => 5900.0, 'twiceM' => 40.0]]), 'a junction crossed twice is no out-and-back');
    }

    public function testRiddenTwiceMeasuresAnOutAndBack(): void
    {
        $east = static fn (float $m): array => [5.0 + $m / (111320.0 * cos(52.0 * M_PI / 180.0)), 52.0];
        $north = static fn (float $x, float $m): array => [$east($x)[0], 52.0 + $m / 111320.0];
        $straight = [$east(0), $east(1000), $east(2000), $east(3000)];
        self::assertSame(0.0, GravelHints::twiceM($straight));
        // East 1 km, 500 m north up a spur and back down it, then on east.
        $spur = [$east(0), $east(1000), $north(1000, 500), $east(1000), $east(2000)];
        self::assertEqualsWithDelta(500.0, GravelHints::twiceM($spur), 60.0);
    }

    public function testOnlyGravelAndMountainBikesSeekIt(): void
    {
        self::assertTrue(GravelHints::seeks(BikeType::Gravel));
        self::assertTrue(GravelHints::seeks(BikeType::Mtb));
        foreach ([BikeType::Road, BikeType::Ebike, BikeType::Handbike, BikeType::Recumbent, BikeType::Trike, BikeType::Tandem] as $bike) {
            self::assertFalse(GravelHints::seeks($bike), $bike->value);
        }
    }

    public function testUnpavedIsGravelAndRough(): void
    {
        self::assertSame(1500.0, GravelHints::unpavedM(['paved' => 9000, 'gravel' => 1000, 'rough' => 500, 'unknown' => 200]));
        self::assertSame(0.0, GravelHints::unpavedM(null));
    }

    /** The Ederheide, 2026-10-25: 5.1 of BRouter's 6 km unpaved were sand, heavy going on narrow tyres. */
    public function testSandIsNoGainForAGravelBikeButIsForAMountainBike(): void
    {
        $surfaces = ['paved' => 6000, 'gravel' => 700, 'rough' => 5300, 'unknown' => 0, 'sand' => 5100];
        self::assertSame(900.0, GravelHints::unpavedM($surfaces, BikeType::Gravel));
        self::assertSame(6000.0, GravelHints::unpavedM($surfaces, BikeType::Mtb));
    }
}
