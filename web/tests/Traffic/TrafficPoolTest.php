<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Traffic;

use App\Traffic\TrafficPool;
use App\Traffic\TrafficStore;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The waiting room (docs/specs/traffic-measurements.md §4.3): a line waits,
 * encrypted and on its own, until its block (road, direction, part of the day,
 * day type) has 5 lines from 3 day groups; then the whole block goes into the
 * totals at once, and at most one block per call, so a ride reaches the
 * totals in pieces.
 */
final class TrafficPoolTest extends KernelTestCase
{
    private TrafficPool $pool;
    private TrafficStore $store;
    private Connection $db;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->pool = static::getContainer()->get(TrafficPool::class);
        $this->store = static::getContainer()->get(TrafficStore::class);
        $this->db = static::getContainer()->get(Connection::class);
    }

    /** @param list<int> $groups day groups, one line each */
    private function wait(array $groups, array $over = []): void
    {
        foreach ($groups as $g) {
            $this->pool->add(TrafficStoreTest::line(['dayGroup' => $g] + $over));
        }
    }

    private function waiting(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM traffic_pool');
    }

    /** @return list<array<string, mixed>> */
    private function totals(): array
    {
        return iterator_to_array($this->store->totals(), false);
    }

    public function testAWaitingLineIsSealedAndCarriesNoAccountOrTime(): void
    {
        $this->wait([9]);

        $columns = $this->db->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_name = 'traffic_pool' ORDER BY column_name");
        self::assertSame(['block_key', 'id', 'payload'], $columns);
        foreach ($this->db->fetchAllAssociative('SELECT * FROM traffic_pool') as $row) {
            $bytes = implode('', array_map(static fn ($v) => \is_resource($v) ? stream_get_contents($v) : (string) $v, $row));
            foreach (['4521877', '2026-Q4', 'workday', '3210', pack('N', 4521877), pack('V', 4521877)] as $needle) {
                self::assertStringNotContainsString($needle, $bytes, "traffic_pool leaks {$needle}");
            }
        }
    }

    public function testFourLinesWait(): void
    {
        $this->wait([1, 2, 3, 4]);

        self::assertSame(0, $this->pool->releaseOne());
        self::assertSame(4, $this->waiting());
        self::assertSame([], $this->totals());
    }

    public function testFiveLinesFromThreeDayGroupsLeaveTogether(): void
    {
        $this->wait([1, 2, 3, 3, 3]);

        self::assertSame(5, $this->pool->releaseOne());
        self::assertSame(0, $this->waiting());
        $rows = $this->totals();
        self::assertCount(1, $rows);
        self::assertSame(5, $rows[0]['lines']);
        self::assertSame(5 * 3210, $rows[0]['distanceM']);
    }

    public function testFiveLinesFromTwoDayGroupsWait(): void
    {
        $this->wait([1, 1, 1, 2, 2]);

        self::assertSame(0, $this->pool->releaseOne(), 'one group ride on one day must not fill a block');
        self::assertSame(5, $this->waiting());
    }

    public function testTheSameDayGroupInAnotherQuarterIsAnotherDay(): void
    {
        $this->wait([1, 1, 1], ['quarter' => '2026-Q3']);
        $this->wait([1, 1], ['quarter' => '2026-Q4']);
        self::assertSame(0, $this->pool->releaseOne(), 'two day groups so far');

        $this->wait([2], ['quarter' => '2026-Q4']);
        self::assertSame(6, $this->pool->releaseOne(), 'quarters wait together in one block');
        self::assertEqualsCanonicalizing(['2026-Q3', '2026-Q4'], array_column($this->totals(), 'quarter'), 'each line lands in its own quarter');
    }

    public function testAtMostOneBlockLeavesPerCall(): void
    {
        $this->wait([1, 2, 3, 4, 5]);
        $this->wait([1, 2, 3, 4, 5], ['band' => 1]);

        self::assertSame(5, $this->pool->releaseOne());
        self::assertSame(5, $this->waiting(), 'the other block waits for the next send');
        self::assertSame(5, $this->pool->releaseOne());
        self::assertSame(0, $this->waiting());
    }

    public function testABlockIsOneRoadDirectionPartOfDayAndDayType(): void
    {
        $this->wait([1, 2, 3]);
        $this->wait([4, 5], ['dir' => 'b']);

        self::assertSame(0, $this->pool->releaseOne(), 'three lines one way and two the other are two blocks');
    }
}
