<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Traffic;

use App\Traffic\TrafficCipher;
use App\Traffic\TrafficKeys;
use App\Traffic\TrafficPool;
use App\Traffic\TrafficStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\AbstractLogger;
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

    /** A pool that writes its warnings to $log. */
    private function poolLogging(TrafficPoolLog $log): TrafficPool
    {
        $c = static::getContainer();

        return new TrafficPool($this->db, $c->get(TrafficKeys::class), $c->get(TrafficCipher::class), $this->store, $log);
    }

    /** @param array<string, mixed> $over */
    private function blockKey(array $over = []): string
    {
        return static::getContainer()->get(TrafficKeys::class)->blockKey(TrafficStoreTest::line($over));
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

    public function testARowMovedToAnotherBlockDoesNotOpen(): void
    {
        $this->wait([1, 2, 3, 4]);
        $this->wait([5], ['band' => 1]);
        $this->db->executeStatement('UPDATE traffic_pool SET block_key = ? WHERE block_key = ?',
            [$this->blockKey(), $this->blockKey(['band' => 1])], [ParameterType::BINARY, ParameterType::BINARY]);
        $log = new TrafficPoolLog();

        self::assertSame(0, $this->poolLogging($log)->releaseOne(), 'the moved line must not complete the block');
        self::assertSame(5, $this->waiting());
        self::assertCount(1, $log->records);
    }

    public function testABlockWithARowThatWillNotOpenIsSkippedAndKept(): void
    {
        $this->wait([1, 2, 3, 4, 5]);
        $this->db->executeStatement('UPDATE traffic_pool SET payload = ? WHERE id = (SELECT id FROM traffic_pool LIMIT 1)',
            [random_bytes(300)], [ParameterType::BINARY]);
        $this->wait([1, 2, 3, 4, 5], ['band' => 1]);
        $log = new TrafficPoolLog();
        $pool = $this->poolLogging($log);

        self::assertSame(5, $pool->releaseOne() + $pool->releaseOne(), 'the good block leaves; the other is passed over');
        self::assertSame(5, $this->waiting(), 'the block that will not open is neither released nor deleted');
        self::assertCount(1, $this->totals());
        self::assertNotEmpty($log->records);
        foreach ($log->records as [$level, $message, $context]) {
            self::assertSame('warning', $level);
            $text = $message.json_encode($context, \JSON_THROW_ON_ERROR);
            foreach (['4521877', '2026-Q4', 'workday', '3210'] as $needle) {
                self::assertStringNotContainsString($needle, $text, 'the warning carries no line content');
            }
        }
    }

    public function testOneCallLooksAtABoundedSampleOfBlocks(): void
    {
        // Blocks that will not open: each one looked at logs one warning.
        for ($b = 0; $b < TrafficPool::MAX_CANDIDATES + 8; ++$b) {
            $block = random_bytes(32);
            for ($i = 0; $i < TrafficPool::MIN_LINES; ++$i) {
                $this->db->executeStatement('INSERT INTO traffic_pool (id, block_key, payload) VALUES (?, ?, ?)',
                    [random_bytes(16), $block, random_bytes(300)], array_fill(0, 3, ParameterType::BINARY));
            }
        }
        $log = new TrafficPoolLog();

        self::assertSame(0, $this->poolLogging($log)->releaseOne());
        self::assertCount(TrafficPool::MAX_CANDIDATES, $log->records, 'one call opens at most MAX_CANDIDATES blocks');
    }

    public function testAReadyBlockAmongManyThatWaitIsFoundInTime(): void
    {
        for ($way = 1; $way <= TrafficPool::MAX_CANDIDATES * 2; ++$way) {
            $this->wait([1, 1, 1, 1, 1], ['way' => $way]);
        }
        $this->wait([1, 2, 3, 4, 5]);

        $moved = 0;
        for ($call = 0; $call < 50 && 0 === $moved; ++$call) {
            $moved = $this->pool->releaseOne();
        }
        self::assertSame(5, $moved, 'the sample is drawn afresh on every call');
    }

    public function testABlockBiggerThanOneReleaseLeavesInParts(): void
    {
        $groups = [];
        for ($i = 0; $i < TrafficPool::MAX_RELEASE + 10; ++$i) {
            $groups[] = $i % 5;
        }
        $this->wait($groups);

        self::assertSame(TrafficPool::MAX_RELEASE, $this->pool->releaseOne());
        self::assertSame(10, $this->waiting());
        self::assertSame(10, $this->pool->releaseOne(), 'the rest still holds 5 lines from 3 day groups');
        self::assertSame(TrafficPool::MAX_RELEASE + 10, $this->totals()[0]['lines']);
    }
}

/** Collects what TrafficPool logs. */
final class TrafficPoolLog extends AbstractLogger
{
    /** @var list<array{string, string, array<array-key, mixed>}> */
    public array $records = [];

    /** @param array<array-key, mixed> $context */
    #[\Override]
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [(string) $level, (string) $message, $context];
    }
}
