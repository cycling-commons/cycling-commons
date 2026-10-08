<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Traffic;

use App\Traffic\TrafficLine;
use App\Traffic\TrafficPayloadRefused;
use PHPUnit\Framework\TestCase;

/**
 * Validation of one summary line (docs/specs/traffic-measurements.md §4.1):
 * a malformed request is refused whole, an implausible line is dropped.
 */
final class TrafficLineTest extends TestCase
{
    /** @return array<string, mixed> */
    public static function line(array $over = []): array
    {
        return $over + [
            'way' => 4521877, 'dir' => 'f', 'label' => 'r', 'band' => 3, 'dayType' => 'workday',
            'quarter' => '2026-Q4', 'dayGroup' => 9,
            'distanceM' => 3210, 'timeS' => 421, 'passes' => 7, 'avgSpeedKmh' => 27.4,
            'carSpeedBins' => [0, 0, 0, 0, 0, 1, 3, 2, 1, 0, 0, 0, 0, 0, 0, 0],
            'blocks' => [str_repeat('a', 64), str_repeat('b', 64)],
        ];
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-05 12:00:00 UTC');
    }

    public function testAPlausibleLineIsKeptWithTheSpeedRecomputed(): void
    {
        $got = TrafficLine::accept(self::line(['avgSpeedKmh' => 99.0]), self::now());

        self::assertNotNull($got);
        self::assertSame(4521877, $got['way']);
        self::assertEqualsWithDelta(3210 / 421 * 3.6, $got['avgSpeedKmh'], 0.01, 'the server computes the speed itself');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function implausible(): iterable
    {
        yield 'no time' => [self::line(['timeS' => 0])];
        yield 'longer than its band and the boundary' => [self::line(['band' => 1, 'timeS' => 10806, 'distanceM' => 60000])];
        yield 'over 200 km' => [self::line(['band' => 2, 'timeS' => 25000, 'distanceM' => 200001])];
        yield 'walking pace' => [self::line(['distanceM' => 50, 'timeS' => 400])];
        yield 'motorway pace' => [self::line(['distanceM' => 20000, 'timeS' => 600])];
        yield 'too many cars per km' => [self::line(['distanceM' => 1000, 'timeS' => 200, 'passes' => 61])];
        yield 'more speeds than cars' => [self::line(['passes' => 2])];
        yield 'fifteen bins' => [self::line(['carSpeedBins' => array_fill(0, 15, 0)])];
        yield 'negative bin' => [self::line(['carSpeedBins' => [-1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]])];
        yield 'band 5' => [self::line(['band' => 5])];
        yield 'band as text' => [self::line(['band' => '3'])];
        yield 'day group 16' => [self::line(['dayGroup' => 16])];
        yield 'day group as text' => [self::line(['dayGroup' => '9'])];
        yield 'no day group' => [array_diff_key(self::line(), ['dayGroup' => true])];
        yield 'unknown label' => [self::line(['label' => 'x'])];
        yield 'unknown direction' => [self::line(['dir' => 'n'])];
        yield 'quarter in the future' => [self::line(['quarter' => '2027-Q1'])];
        yield 'quarter before the project' => [self::line(['quarter' => '2009-Q4'])];
        yield 'no block' => [self::line(['blocks' => []])];
        yield 'more blocks than the longest band holds' => [self::line(['blocks' => array_map(static fn (int $i): string => str_pad(dechex($i), 64, '0', \STR_PAD_LEFT), range(1, 86))])];
        yield 'block not hex' => [self::line(['blocks' => [str_repeat('z', 64)]])];
        yield 'way zero' => [self::line(['way' => 0])];
        yield 'way as text' => [self::line(['way' => '4521877'])];
    }

    /** @dataProvider implausible */
    #[\PHPUnit\Framework\Attributes\DataProvider('implausible')]
    public function testAnImplausibleLineIsDropped(array $line): void
    {
        self::assertNull(TrafficLine::accept($line, self::now()));
    }

    public function testATrackShapedKeyRefusesTheWholeRequest(): void
    {
        foreach (['lat', 'lng', 'lon', 'time', 'timestamp', 'coordinates', 'points', 'track'] as $key) {
            try {
                TrafficLine::accept(self::line([$key => 1]), self::now());
                self::fail("{$key} was not refused");
            } catch (TrafficPayloadRefused $e) {
                self::assertSame('track_not_accepted', $e->getMessage());
            }
        }
    }

    public function testAnUnknownKeyRefusesTheWholeRequest(): void
    {
        $this->expectException(TrafficPayloadRefused::class);
        $this->expectExceptionMessage('invalid');
        TrafficLine::accept(self::line(['note' => 'hi']), self::now());
    }

    public function testAFullBandPlusTheBoundarySecondsIsKept(): void
    {
        // The morning rush runs 06-09: three hours, plus the one interval a band boundary hands on.
        self::assertNotNull(TrafficLine::accept(self::line(['band' => 1, 'timeS' => 10805, 'distanceM' => 60000]), self::now()));
        self::assertNull(TrafficLine::accept(self::line(['band' => 1, 'timeS' => 10806, 'distanceM' => 60000]), self::now()));
        self::assertNotNull(TrafficLine::accept(self::line(['band' => 2, 'timeS' => 25205, 'distanceM' => 150000]), self::now()), 'the day band runs 09-16');
    }

    public function testADateAQuarterHourASeasonOrARiderRefusesTheWholeRequest(): void
    {
        // A time finer than a part of the day, a date, or anything naming a rider is never accepted.
        foreach (['slot' => 73, 'season' => 'autumn', 'day' => 20731, 'rider' => str_repeat('5b', 32)] as $key => $value) {
            try {
                TrafficLine::accept(self::line([$key => $value]), self::now());
                self::fail("{$key} was not refused");
            } catch (TrafficPayloadRefused $e) {
                self::assertSame('invalid', $e->getMessage());
            }
        }
    }

    public function testTheDayGroupIsKept(): void
    {
        self::assertSame(9, TrafficLine::accept(self::line(), self::now())['dayGroup'] ?? null);
        self::assertSame(0, TrafficLine::accept(self::line(['dayGroup' => 0]), self::now())['dayGroup'] ?? null);
    }

    public function testANewQuarterAlreadyBegunEastOfGreenwichIsKept(): void
    {
        $lateSeptember = new \DateTimeImmutable('2026-09-30 23:30:00 UTC');
        // 1 October local, in a zone ahead of UTC.
        self::assertNotNull(TrafficLine::accept(self::line(['quarter' => '2026-Q4']), $lateSeptember));
        self::assertNull(TrafficLine::accept(self::line(['quarter' => '2027-Q1']), $lateSeptember));
    }

    public function testCarsBesideACyclePathAreNearbyNeverPassing(): void
    {
        // No car drives on a cycle path: what the radar saw there drove on the road beside it.
        $path = TrafficLine::accept(self::line(['label' => 'p', 'passes' => 0, 'nearby' => 5, 'carSpeedBins' => null]), self::now());
        self::assertNotNull($path);
        self::assertSame(0, $path['passes']);
        self::assertSame(5, $path['nearby']);
        self::assertNull(TrafficLine::accept(self::line(['label' => 'p', 'passes' => 3, 'carSpeedBins' => null]), self::now()), 'a car passing on a cycle path');
    }

    public function testCarsOnARoadOrLaneArePassingNeverNearby(): void
    {
        $road = TrafficLine::accept(self::line(), self::now());
        self::assertNotNull($road);
        self::assertSame(0, $road['nearby'], 'absent means none');
        self::assertNull(TrafficLine::accept(self::line(['label' => 'l', 'nearby' => 2]), self::now()));
    }

    public function testNearbyCarsCarryTheirSpeedsAndTheSameLimits(): void
    {
        $bins = [0, 0, 0, 0, 0, 1, 3, 1, 0, 0, 0, 0, 0, 0, 0, 0];
        self::assertNotNull(TrafficLine::accept(self::line(['label' => 'p', 'passes' => 0, 'nearby' => 5, 'carSpeedBins' => $bins]), self::now()));
        self::assertNull(TrafficLine::accept(self::line(['label' => 'p', 'passes' => 0, 'nearby' => 4, 'carSpeedBins' => $bins]), self::now()), 'more speeds than cars');
        self::assertNull(TrafficLine::accept(self::line(['label' => 'p', 'passes' => 0, 'nearby' => 301, 'distanceM' => 20000, 'timeS' => 900]), self::now()));
    }

    public function testALineKeepsTheRegionItsRoadPieceNamed(): void
    {
        self::assertSame(12, TrafficLine::accept(self::line(['region' => 12]), self::now())['region'] ?? null);
        $plain = TrafficLine::accept(self::line(), self::now());
        self::assertNotNull($plain);
        self::assertArrayHasKey('region', $plain);
        self::assertNull($plain['region'], 'absent means unknown');
        self::assertNull(TrafficLine::accept(self::line(['region' => 0]), self::now()));
        self::assertNull(TrafficLine::accept(self::line(['region' => '12']), self::now()));
    }
}
