<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Traffic;

use App\Traffic\TrafficDisclosure;
use PHPUnit\Framework\TestCase;

/**
 * The rules before anything is shown (docs/specs/traffic-measurements.md §4.4):
 * enough lines, on enough day groups, over enough distance. What comes out is a
 * band (quiet, moderate, busy), never a number; below the rules nothing at all
 * comes out, not even an empty entry.
 */
final class TrafficDisclosureTest extends TestCase
{
    /** A totals row of 5 lines, 1 km each, 8 cars each, on day groups 0, 1 and 2. */
    private static function row(array $over = []): array
    {
        return $over + [
            'way' => 7, 'dir' => 'f', 'label' => 'r', 'band' => 3, 'dayType' => 'workday', 'quarter' => '2026-Q4', 'region' => null,
            'distanceM' => 5000, 'timeS' => 900, 'passes' => 40, 'nearby' => 0, 'speedSum' => 0.0, 'speedPasses' => 0,
            'bins' => array_fill(0, 16, 0), 'lines' => 5, 'days' => 0b111,
        ];
    }

    private static function evaluate(array $rows, string $scheme = 'daytype'): array
    {
        return new TrafficDisclosure()->evaluate($rows, $scheme, '2024-Q1');
    }

    public function testFiveLinesOnThreeDayGroupsWithEnoughDistanceAreShownAsABand(): void
    {
        $shown = self::evaluate([self::row()]);

        self::assertCount(1, $shown);
        self::assertSame(7, $shown[0]['way']);
        self::assertSame('workday', $shown[0]['group']);
        self::assertSame('busy', $shown[0]['traffic'], '8 cars per km');
        self::assertSame('3-9', $shown[0]['days']);
        self::assertArrayNotHasKey('carsPerKm', $shown[0], 'never a number');
        self::assertArrayNotHasKey('riders', $shown[0]);
    }

    public function testTheBandsSplitAtOneAndThreeCarsPerKm(): void
    {
        self::assertSame('quiet', self::evaluate([self::row(['passes' => 4])])[0]['traffic'], '0.8 per km');
        self::assertSame('moderate', self::evaluate([self::row(['passes' => 5])])[0]['traffic'], '1.0 per km');
        self::assertSame('moderate', self::evaluate([self::row(['passes' => 14])])[0]['traffic'], '2.8 per km');
        self::assertSame('busy', self::evaluate([self::row(['passes' => 15])])[0]['traffic'], '3.0 per km');
    }

    public function testFourLinesShowNothing(): void
    {
        self::assertSame([], self::evaluate([self::row(['lines' => 4, 'distanceM' => 4000])]));
    }

    public function testTwoDayGroupsShowNothing(): void
    {
        self::assertSame([], self::evaluate([self::row(['days' => 0b11])]), 'one group ride is one moment of traffic');
    }

    public function testTooLittleDistanceShowsNothing(): void
    {
        self::assertSame([], self::evaluate([self::row(['distanceM' => 1900])]));
    }

    public function testDayGroupsAreCountedPerQuarterAndAddedUp(): void
    {
        $q3 = self::row(['quarter' => '2026-Q3', 'days' => 0b11, 'lines' => 3, 'distanceM' => 3000]);
        $q4 = self::row(['quarter' => '2026-Q4', 'days' => 0b11, 'lines' => 2, 'distanceM' => 2000]);
        self::assertCount(1, self::evaluate([$q3, $q4]), 'two groups in each of two quarters are four days');

        $morning = self::row(['band' => 1, 'days' => 0b11, 'lines' => 3, 'distanceM' => 3000]);
        $evening = self::row(['band' => 3, 'days' => 0b11, 'lines' => 2, 'distanceM' => 2000]);
        self::assertSame([], self::evaluate([$morning, $evening], 'all'), 'the same two groups in one quarter are two days');
    }

    public function testWorkdayAndWeekendAreSeparateGroupsThatEachNeedTheRules(): void
    {
        $weekend = self::row(['dayType' => 'weekend', 'lines' => 2]);
        self::assertSame(['workday'], array_column(self::evaluate([self::row(), $weekend]), 'group'));
    }

    public function testTheBandSchemeSplitsTheDayAndTheAllSchemeJoinsIt(): void
    {
        $morning = self::row(['band' => 1, 'lines' => 3, 'distanceM' => 3000, 'days' => 0b111]);
        $evening = self::row(['band' => 3, 'lines' => 3, 'distanceM' => 3000, 'days' => 0b111000]);

        self::assertSame([], self::evaluate([$morning, $evening], 'daytype_band'), 'three lines per band');
        $all = self::evaluate([$morning, $evening], 'all');
        self::assertCount(1, $all);
        self::assertSame('all', $all[0]['group']);
        self::assertSame('workday|morning', TrafficDisclosure::groupsOf('daytype_band')[1]);
    }

    public function testDataOlderThanThePeriodIsLeftOut(): void
    {
        self::assertSame([], self::evaluate([self::row(['quarter' => '2023-Q4'])]));
    }

    public function testTheCarSpeedBandNeedsFiveMeasuredSpeeds(): void
    {
        $bins = array_fill(0, 16, 0);
        $bins[6] = 3;
        $bins[7] = 1;
        self::assertNull(self::evaluate([self::row(['bins' => $bins, 'speedPasses' => 4])])[0]['carSpeedBand']);

        $bins[7] = 2;
        self::assertSame(60, self::evaluate([self::row(['bins' => $bins, 'speedPasses' => 5])])[0]['carSpeedBand'], 'the median falls in 60-69 km/h');
    }

    public function testDirectionsAreSeparateGroups(): void
    {
        $shown = self::evaluate([self::row(), self::row(['dir' => 'b'])]);
        self::assertEqualsCanonicalizing(['f', 'b'], array_column($shown, 'dir'));
    }

    public function testACyclePathShowsNoPassingCarsAndTheBandOfItsNearbyOnes(): void
    {
        $shown = self::evaluate([self::row(['label' => 'p', 'passes' => 0, 'nearby' => 40])]);

        self::assertSame('quiet', $shown[0]['traffic']);
        self::assertSame('busy', $shown[0]['nearby']);
    }

    public function testCoarseDayBands(): void
    {
        self::assertSame('3-9', TrafficDisclosure::dayBand(9));
        self::assertSame('10+', TrafficDisclosure::dayBand(10));
    }
}
