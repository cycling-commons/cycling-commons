<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Traffic;

use App\Traffic\TrafficStore;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The plain traffic totals and the dedupe codes (docs/specs/traffic-measurements.md
 * §4.3): sums per road, direction, part of the day, day type and quarter that
 * add up, the day only as its group, and no rider anywhere.
 */
final class TrafficStoreTest extends KernelTestCase
{
    private TrafficStore $store;
    private Connection $db;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->store = static::getContainer()->get(TrafficStore::class);
        $this->db = static::getContainer()->get(Connection::class);
    }

    /** @return array<string, mixed> */
    public static function line(array $over = []): array
    {
        return $over + [
            'way' => 4521877, 'region' => null, 'dir' => 'f', 'label' => 'r', 'band' => 3, 'dayType' => 'workday',
            'quarter' => '2026-Q4', 'dayGroup' => 9,
            'distanceM' => 3210, 'timeS' => 421, 'passes' => 7, 'nearby' => 0, 'avgSpeedKmh' => 27.4,
            'carSpeedBins' => [0, 0, 0, 0, 0, 1, 3, 2, 1, 0, 0, 0, 0, 0, 0, 0],
            'blocks' => [str_repeat('a', 64)],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function totals(): array
    {
        return iterator_to_array($this->store->totals(), false);
    }

    public function testTwoLinesOfOneKeyAddUp(): void
    {
        $this->store->addToTotal(self::line());
        $this->store->addToTotal(self::line(['distanceM' => 1000, 'timeS' => 200, 'passes' => 1, 'carSpeedBins' => null, 'dayGroup' => 4]));

        $rows = $this->totals();
        self::assertCount(1, $rows);
        $t = $rows[0];
        self::assertSame(4521877, $t['way']);
        self::assertSame(4210, $t['distanceM']);
        self::assertSame(621, $t['timeS']);
        self::assertSame(8, $t['passes']);
        self::assertSame(7, $t['speedPasses'], 'only the first line measured car speeds');
        self::assertSame(2, $t['lines']);
        self::assertSame(3, $t['bins'][6]);
        self::assertSame((1 << 9) | (1 << 4), $t['days'], 'the day groups, never a date');
    }

    public function testAnotherPartOfTheDayIsAnotherRow(): void
    {
        $this->store->addToTotal(self::line());
        $this->store->addToTotal(self::line(['band' => 4]));

        self::assertCount(2, $this->totals());
    }

    public function testNearbyCarsAddUpApartFromPassingOnes(): void
    {
        $path = ['label' => 'p', 'passes' => 0, 'nearby' => 4];
        $this->store->addToTotal(self::line($path));
        $this->store->addToTotal(self::line($path + ['distanceM' => 1000, 'timeS' => 200, 'carSpeedBins' => null]));

        $rows = $this->totals();
        self::assertSame(8, $rows[0]['nearby']);
        self::assertSame(0, $rows[0]['passes']);
    }

    public function testARowKeepsItsRoadsRegion(): void
    {
        $this->store->addToTotal(self::line(['region' => 12]));
        $this->store->addToTotal(self::line(['distanceM' => 500, 'timeS' => 90, 'carSpeedBins' => null]));

        self::assertSame(12, $this->totals()[0]['region'], 'a later line without one does not erase it');
    }

    public function testACodeIsClaimedOnce(): void
    {
        $a = str_repeat('a', 64);
        $b = str_repeat('b', 64);

        self::assertSame([$a, $b], $this->store->claimCodes([$b, $a, $a]));
        self::assertSame([], $this->store->claimCodes([$a]), 'a second claim gets nothing');
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM traffic_seen'));
    }

    public function testNoTableHoldsARiderOrADate(): void
    {
        $columns = $this->db->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_name = 'traffic_total'");
        foreach ($columns as $column) {
            self::assertDoesNotMatchRegularExpression('/rider|user|account|voice|day$|date|time_at|created|updated/', (string) $column);
        }
        foreach (['traffic_rider', 'traffic_cell'] as $gone) {
            self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM information_schema.tables WHERE table_name = ?', [$gone]));
        }
    }
}
