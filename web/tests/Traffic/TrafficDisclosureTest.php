<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Traffic;

use App\Traffic\TrafficDisclosure;
use PHPUnit\Framework\TestCase;

/**
 * The rules before anything is shown (docs/specs/traffic-measurements.md §4.4):
 * several riders, several days, no dominant rider, enough distance. Below them,
 * nothing at all comes out, not even an empty entry.
 */
final class TrafficDisclosureTest extends TestCase
{
    private static function cell(array $over = []): array
    {
        return $over + [
            'way' => 7, 'dir' => 'f', 'label' => 'r', 'slot' => 73, 'dayType' => 'workday', 'season' => 'autumn',
            'quarter' => '2026-Q4', 'distanceM' => 0, 'timeS' => 0, 'passes' => 0, 'speedSum' => 0.0,
            'speedPasses' => 0, 'bins' => array_fill(0, 16, 0), 'contributions' => 0,
        ];
    }

    private static function bucket(array $cell): string
    {
        return implode('|', [$cell['way'], $cell['dir'], $cell['label'], $cell['slot'], $cell['dayType'], $cell['season'], $cell['quarter']]);
    }

    /**
     * A cell and its riders: each rider rode `$metres` on its own day unless days are given.
     *
     * @param list<int>       $metres per rider
     * @param list<list<int>> $days   per rider
     */
    private static function scene(array $metres, array $days = [], array $cellOver = [], int $passes = 40): array
    {
        $cell = self::cell($cellOver + ['distanceM' => array_sum($metres), 'passes' => $passes, 'contributions' => \count($metres)]);
        $riders = [];
        foreach ($metres as $i => $m) {
            $riders[] = ['way' => $cell['way'], 'buckets' => [self::bucket($cell) => ['d' => $m, 'days' => $days[$i] ?? [20000 + $i]]]];
        }

        return [[$cell], $riders];
    }

    private static function evaluate(array $cells, array $riders, string $scheme = 'daytype'): array
    {
        return new TrafficDisclosure()->evaluate($cells, $riders, $scheme, '2024-Q1', self::bucket(...));
    }

    public function testFiveRidersOnFiveDaysWithEnoughDistanceAreShown(): void
    {
        [$cells, $riders] = self::scene([1000, 1000, 1000, 1000, 1000]);
        $shown = self::evaluate($cells, $riders);

        self::assertCount(1, $shown);
        self::assertSame(7, $shown[0]['way']);
        self::assertSame('workday', $shown[0]['group']);
        self::assertSame(8.0, $shown[0]['carsPerKm']);
        self::assertSame('5-9', $shown[0]['riders']);
        self::assertSame('3-9', $shown[0]['days']);
    }

    public function testFourRidersShowNothing(): void
    {
        [$cells, $riders] = self::scene([2000, 2000, 2000, 2000]);
        self::assertSame([], self::evaluate($cells, $riders));
    }

    public function testFiveRidersOnTwoDaysShowNothing(): void
    {
        [$cells, $riders] = self::scene([1000, 1000, 1000, 1000, 1000], [[1], [1], [1], [2], [2]]);
        self::assertSame([], self::evaluate($cells, $riders), 'one group ride is one moment of traffic');
    }

    public function testOneRiderWithMostOfTheDistanceShowsNothing(): void
    {
        // Four riders once, one rider every weekend: the regular would be visible.
        [$cells, $riders] = self::scene([500, 500, 500, 500, 2500]);
        self::assertSame([], self::evaluate($cells, $riders));
    }

    public function testTooLittleDistanceShowsNothing(): void
    {
        [$cells, $riders] = self::scene([380, 380, 380, 380, 380]);
        self::assertSame([], self::evaluate($cells, $riders));
    }

    public function testWorkdayAndWeekendAreSeparateGroupsThatEachNeedTheRules(): void
    {
        [$a, $ra] = self::scene([1000, 1000, 1000, 1000, 1000]);
        [$b, $rb] = self::scene([1000, 1000], [], ['dayType' => 'weekend']);
        $shown = self::evaluate([...$a, ...$b], [...$ra, ...$rb]);

        self::assertSame(['workday'], array_column($shown, 'group'));
    }

    public function testTheBandSchemeSplitsTheDayAndTheAllSchemeJoinsIt(): void
    {
        [$a, $ra] = self::scene([1000, 1000, 1000], [], ['slot' => 30]);   // 07:30, morning
        [$b, $rb] = self::scene([1000, 1000, 1000], [], ['slot' => 70]);   // 17:30, evening
        // Different riders in each: make the second group's riders distinct.
        $rb = array_map(static fn ($r, $i) => $r + ['_' => $i], $rb, array_keys($rb));
        foreach ($rb as $i => $r) {
            $rb[$i]['buckets'][array_key_first($r['buckets'])]['days'] = [21000 + $i];
        }

        self::assertSame([], self::evaluate([...$a, ...$b], [...$ra, ...$rb], 'daytype_band'), 'three riders per band');
        $all = self::evaluate([...$a, ...$b], [...$ra, ...$rb], 'all');
        self::assertCount(1, $all);
        self::assertSame('all', $all[0]['group']);
    }

    public function testDataOlderThanThePeriodIsLeftOut(): void
    {
        [$cells, $riders] = self::scene([1000, 1000, 1000, 1000, 1000], [], ['quarter' => '2023-Q4']);
        self::assertSame([], self::evaluate($cells, $riders));
    }

    public function testTheCarSpeedBandNeedsFiveMeasuredSpeeds(): void
    {
        $bins = array_fill(0, 16, 0);
        $bins[6] = 3;
        $bins[7] = 1;
        [$cells, $riders] = self::scene([1000, 1000, 1000, 1000, 1000], [], ['bins' => $bins, 'speedPasses' => 4]);
        self::assertNull(self::evaluate($cells, $riders)[0]['carSpeedBand']);

        $bins[7] = 2;
        [$cells, $riders] = self::scene([1000, 1000, 1000, 1000, 1000], [], ['bins' => $bins, 'speedPasses' => 5]);
        self::assertSame(60, self::evaluate($cells, $riders)[0]['carSpeedBand'], 'the median falls in 60-69 km/h');
    }

    public function testCoarseBandsInsteadOfCounts(): void
    {
        self::assertSame('5-9', TrafficDisclosure::riderBand(9));
        self::assertSame('10-19', TrafficDisclosure::riderBand(10));
        self::assertSame('20+', TrafficDisclosure::riderBand(31));
        self::assertSame('3-9', TrafficDisclosure::dayBand(9));
        self::assertSame('10+', TrafficDisclosure::dayBand(10));
    }

    public function testDirectionsAreSeparateGroups(): void
    {
        [$a, $ra] = self::scene([1000, 1000, 1000, 1000, 1000]);
        [$b, $rb] = self::scene([1000, 1000, 1000, 1000, 1000], [], ['dir' => 'b']);
        $shown = self::evaluate([...$a, ...$b], [...$ra, ...$rb]);

        self::assertEqualsCanonicalizing(['f', 'b'], array_column($shown, 'dir'));
    }

    public function testDistanceLeftByADeletedAccountStillCountsAsOneContributor(): void
    {
        // Five small riders remain; a deleted account supplied 9 km of the 10.
        [$cells, $riders] = self::scene([200, 200, 200, 200, 200]);
        $cells[0]['distanceM'] = 10000;

        self::assertSame([], self::evaluate($cells, $riders), 'one person\'s rides must not show because their account is gone');
    }

    public function testACyclePathShowsNoPassingCarsAndItsNearbyOnes(): void
    {
        // 5 km of cycle path beside a road that 40 cars drove on.
        [$cells, $riders] = self::scene([1000, 1000, 1000, 1000, 1000], [], ['label' => 'p', 'nearby' => 40], 0);
        $shown = self::evaluate($cells, $riders);

        self::assertCount(1, $shown);
        self::assertSame(0.0, $shown[0]['carsPerKm']);
        self::assertSame(8.0, $shown[0]['nearbyPerKm']);
    }

    public function testARoadShowsNoNearbyCars(): void
    {
        [$cells, $riders] = self::scene([1000, 1000, 1000, 1000, 1000]);
        $shown = self::evaluate($cells, $riders);

        self::assertSame(8.0, $shown[0]['carsPerKm']);
        self::assertSame(0.0, $shown[0]['nearbyPerKm'], 'a cell written before nearby existed counts none');
    }
}
