<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Traffic;

use App\Traffic\SafetyMark;
use App\Traffic\TrafficPayloadRefused;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Felt-safety marks (docs/specs/traffic-measurements.md §10): one answer, safe
 * or unsafe, for one road or several picked together (owner 2026-10-19: "CTRL
 * + click multiple road pieces together") and for one or several parts of the
 * day, day types and quarters of the last year (owner 2026-10-19: "make all
 * fields checkboxes. I ride there all of the time and it is always the same").
 * Each road and time becomes its own mark. Anything else refuses the whole
 * request.
 */
final class SafetyMarkTest extends TestCase
{
    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-20 12:00', new \DateTimeZone('UTC'));
    }

    /**
     * A request body for one road; `$road` changes that road, `$over` the answer.
     *
     * @param array<string, mixed> $over
     * @param array<string, mixed> $road
     *
     * @return array<string, mixed>
     */
    public static function mark(array $over = [], array $road = []): array
    {
        return $over + [
            'v' => 3, 'roads' => [$road + ['way' => 4521877, 'label' => 'r', 'region' => 12]],
            'bands' => [3], 'dayTypes' => ['workday'], 'quarters' => ['2026-Q4'], 'felt' => 'unsafe',
        ];
    }

    /**
     * The first mark a body makes.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private static function one(array $body, ?\DateTimeImmutable $now = null): array
    {
        return SafetyMark::accept($body, $now ?? self::now())[0];
    }

    public function testAValidMarkComesBackWithoutItsVersion(): void
    {
        self::assertSame(
            [['way' => 4521877, 'label' => 'r', 'region' => 12, 'band' => 3, 'dayType' => 'workday', 'quarter' => '2026-Q4', 'felt' => 'unsafe']],
            SafetyMark::accept(self::mark(), self::now()),
        );
    }

    public function testRoadsPickedTogetherEachBecomeAMarkWithTheSameAnswer(): void
    {
        $marks = SafetyMark::accept(self::mark(['roads' => [
            ['way' => 11, 'label' => 'p', 'region' => 12], ['way' => 12, 'label' => 'r'], ['way' => 13, 'label' => 'l', 'region' => null],
        ]]), self::now());

        self::assertSame([11, 12, 13], array_column($marks, 'way'));
        self::assertSame(['p', 'r', 'l'], array_column($marks, 'label'));
        self::assertSame([12, null, null], array_column($marks, 'region'));
        self::assertSame(['unsafe'], array_values(array_unique(array_column($marks, 'felt'))));
        self::assertSame(['2026-Q4'], array_values(array_unique(array_column($marks, 'quarter'))));
    }

    public function testEveryRoadAndTimeChosenBecomesAMark(): void
    {
        $marks = SafetyMark::accept(self::mark([
            'roads' => [['way' => 11, 'label' => 'p'], ['way' => 12, 'label' => 'r']],
            'bands' => [1, 3], 'dayTypes' => ['workday', 'weekend'], 'quarters' => ['2026-Q4', '2026-Q3'],
        ]), self::now());

        self::assertCount(2 * 2 * 2 * 2, $marks);
        $keys = array_map(static fn (array $m): string => $m['way'].'|'.$m['band'].'|'.$m['dayType'].'|'.$m['quarter'], $marks);
        self::assertCount(16, array_unique($keys), 'each road and time once');
        self::assertContains('12|1|weekend|2026-Q3', $keys);
    }

    public function testAtMostTwoHundredMarksInOneAnswer(): void
    {
        $roads = array_map(static fn (int $w): array => ['way' => $w, 'label' => 'r'], range(1, 20));
        // 20 roads x 5 parts of the day x 2 day types = 200.
        self::assertCount(SafetyMark::MAX_MARKS, SafetyMark::accept(self::mark(['roads' => $roads, 'bands' => [0, 1, 2, 3, 4], 'dayTypes' => ['workday', 'weekend']]), self::now()));

        $this->expectException(TrafficPayloadRefused::class);
        $this->expectExceptionMessage('invalid');
        SafetyMark::accept(self::mark(['roads' => $roads, 'bands' => [0, 1, 2, 3, 4], 'dayTypes' => ['workday', 'weekend'], 'quarters' => ['2026-Q4', '2026-Q3']]), self::now());
    }

    public function testAtMostFiftyRoadsAndEachOnce(): void
    {
        $roads = array_map(static fn (int $w): array => ['way' => $w, 'label' => 'r'], range(1, SafetyMark::MAX_ROADS));
        self::assertCount(SafetyMark::MAX_ROADS, SafetyMark::accept(self::mark(['roads' => $roads]), self::now()));

        foreach (['51 roads' => [...$roads, ['way' => 99, 'label' => 'r']], 'a road twice' => [['way' => 7, 'label' => 'r'], ['way' => 7, 'label' => 'p']], 'no road' => []] as $what => $list) {
            try {
                SafetyMark::accept(self::mark(['roads' => $list]), self::now());
                self::fail($what.' was taken');
            } catch (TrafficPayloadRefused $e) {
                self::assertSame('invalid', $e->getMessage(), $what);
            }
        }
    }

    public function testTheRegionMayBeAbsentOrNull(): void
    {
        $body = self::mark();
        unset($body['roads'][0]['region']);

        self::assertNull(self::one($body)['region']);
        self::assertNull(self::one(self::mark([], ['region' => null]))['region']);
    }

    public function testThisQuarterAndTheFourBeforeItAreAccepted(): void
    {
        foreach (['2026-Q4', '2026-Q3', '2026-Q2', '2026-Q1', '2025-Q4'] as $q) {
            self::assertSame($q, self::one(self::mark(['quarters' => [$q]]))['quarter']);
        }
    }

    public function testAZoneEastOfGreenwichMayAlreadyBeInTheNextQuarter(): void
    {
        $lastEvening = new \DateTimeImmutable('2026-12-31 22:30', new \DateTimeZone('UTC'));

        self::assertSame('2027-Q1', self::one(self::mark(['quarters' => ['2027-Q1']]), $lastEvening)['quarter']);
    }

    public function testATrackKeyIsRefusedAsATrack(): void
    {
        foreach (['lat', 'lon', 'points', 'time', 'timestamp', 'track'] as $key) {
            foreach (['on the answer' => self::mark([$key => 1]), 'on a road' => self::mark([], [$key => 1])] as $where => $body) {
                try {
                    SafetyMark::accept($body, self::now());
                    self::fail($key.' '.$where.' was taken');
                } catch (TrafficPayloadRefused $e) {
                    self::assertSame('track_not_accepted', $e->getMessage(), $key.' '.$where);
                }
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function refused(): iterable
    {
        yield 'an unknown key' => [self::mark(['rider' => 'x'])];
        yield 'an unknown key on a road' => [self::mark([], ['rider' => 'x'])];
        yield 'a date' => [self::mark(['day' => 20412])];
        yield 'a direction' => [self::mark(['dir' => 'f'])];
        yield 'the one-road version' => [self::mark(['v' => 1])];
        yield 'the one-time version' => [self::mark(['v' => 2])];
        yield 'a single band, not a list' => [self::mark(['bands' => 3])];
        yield 'the old band key' => [self::mark(['band' => 3])];
        yield 'no band' => [self::mark(['bands' => []])];
        yield 'a band twice' => [self::mark(['bands' => [3, 3]])];
        yield 'no day type' => [self::mark(['dayTypes' => []])];
        yield 'a day type twice' => [self::mark(['dayTypes' => ['workday', 'workday']])];
        yield 'no quarter' => [self::mark(['quarters' => []])];
        yield 'a quarter twice' => [self::mark(['quarters' => ['2026-Q4', '2026-Q4']])];
        yield 'no version' => [array_diff_key(self::mark(), ['v' => true])];
        yield 'a way on the answer' => [self::mark(['way' => 4521877])];
        yield 'roads not a list' => [self::mark(['roads' => ['way' => 4521877, 'label' => 'r']])];
        yield 'a road not an object' => [self::mark(['roads' => [4521877]])];
        yield 'a way of zero' => [self::mark([], ['way' => 0])];
        yield 'a way as text' => [self::mark([], ['way' => '4521877'])];
        yield 'no way' => [self::mark(['roads' => [['label' => 'r']]])];
        yield 'another label' => [self::mark([], ['label' => 'x'])];
        yield 'a band of 5' => [self::mark(['bands' => [5]])];
        yield 'a negative band' => [self::mark(['bands' => [-1]])];
        yield 'a band as text' => [self::mark(['bands' => ['3']])];
        yield 'another day type' => [self::mark(['dayTypes' => ['holiday']])];
        yield 'another feeling' => [self::mark(['felt' => 'mixed'])];
        yield 'a feeling as true' => [self::mark(['felt' => true])];
        yield 'a region of zero' => [self::mark([], ['region' => 0])];
        yield 'a region as text' => [self::mark([], ['region' => '12'])];
        yield 'a quarter five back' => [self::mark(['quarters' => ['2025-Q3']])];
        yield 'a quarter ahead' => [self::mark(['quarters' => ['2027-Q1']])];
        yield 'a quarter not a quarter' => [self::mark(['quarters' => ['2026-Q5']])];
        yield 'a month' => [self::mark(['quarters' => ['2026-11']])];
    }

    /** @param array<string, mixed> $mark */
    #[DataProvider('refused')]
    public function testAnythingElseIsRefusedWhole(array $mark): void
    {
        $this->expectException(TrafficPayloadRefused::class);
        $this->expectExceptionMessage('invalid');
        SafetyMark::accept($mark, self::now());
    }
}
