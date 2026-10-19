<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Traffic;

use App\Traffic\SafetyPool;
use App\Traffic\SafetyStore;
use App\Traffic\TrafficKeys;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The felt-safety waiting room (docs/specs/traffic-measurements.md §10): a
 * mark waits, encrypted and on its own, until its block (road, part of the
 * day, day type) has 5 marks; then the block goes into the plain counts, at
 * most one block per call.
 */
final class SafetyPoolTest extends KernelTestCase
{
    private SafetyPool $pool;
    private SafetyStore $store;
    private Connection $db;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->pool = static::getContainer()->get(SafetyPool::class);
        $this->store = static::getContainer()->get(SafetyStore::class);
        $this->db = static::getContainer()->get(Connection::class);
    }

    /** @param array<string, mixed> $over */
    public static function mark(array $over = []): array
    {
        return $over + ['way' => 4521877, 'label' => 'r', 'region' => null, 'band' => 3, 'dayType' => 'workday', 'quarter' => '2026-Q4', 'felt' => 'unsafe'];
    }

    /** @param list<string> $felt one mark each */
    private function wait(array $felt, array $over = []): void
    {
        foreach ($felt as $f) {
            $this->pool->add(self::mark(['felt' => $f] + $over));
        }
    }

    private function waiting(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM safety_pool');
    }

    /** @return list<array<string, mixed>> */
    private function totals(): array
    {
        return iterator_to_array($this->store->totals(), false);
    }

    public function testAWaitingMarkIsSealedAndCarriesNoAccountOrTime(): void
    {
        $this->wait(['unsafe']);

        $columns = $this->db->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_name = 'safety_pool' ORDER BY column_name");
        self::assertSame(['block_key', 'id', 'payload'], $columns);
        foreach ($this->db->fetchAllAssociative('SELECT * FROM safety_pool') as $row) {
            $bytes = implode('', array_map(static fn ($v) => \is_resource($v) ? stream_get_contents($v) : (string) $v, $row));
            foreach (['4521877', '2026-Q4', 'workday', 'unsafe', pack('N', 4521877), pack('V', 4521877)] as $needle) {
                self::assertStringNotContainsString($needle, $bytes, "safety_pool leaks {$needle}");
            }
        }
    }

    public function testTheTotalsHoldNoRiderAndNoDate(): void
    {
        $columns = $this->db->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_name = 'safety_total' ORDER BY column_name");

        self::assertSame(['band', 'day_type', 'label', 'quarter', 'region', 'safe', 'unsafe', 'way'], $columns);
    }

    public function testFourMarksWait(): void
    {
        $this->wait(['safe', 'safe', 'unsafe', 'unsafe']);

        self::assertSame(0, $this->pool->releaseOne());
        self::assertSame(4, $this->waiting());
        self::assertSame([], $this->totals());
    }

    public function testFiveMarksLeaveTogetherAsCounts(): void
    {
        $this->wait(['safe', 'unsafe', 'unsafe', 'unsafe', 'safe'], ['region' => 12]);

        self::assertSame(5, $this->pool->releaseOne());
        self::assertSame(0, $this->waiting());
        $rows = $this->totals();
        self::assertCount(1, $rows);
        self::assertSame(['way' => 4521877, 'band' => 3, 'dayType' => 'workday', 'quarter' => '2026-Q4', 'label' => 'r', 'region' => 12, 'safe' => 2, 'unsafe' => 3], $rows[0]);
    }

    public function testQuartersWaitTogetherAndLandInTheirOwnQuarter(): void
    {
        $this->wait(['safe', 'safe', 'safe'], ['quarter' => '2026-Q3']);
        $this->wait(['unsafe'], ['quarter' => '2026-Q4']);
        self::assertSame(0, $this->pool->releaseOne());

        $this->wait(['unsafe'], ['quarter' => '2026-Q4']);
        self::assertSame(5, $this->pool->releaseOne());
        $byQuarter = array_column($this->totals(), null, 'quarter');
        self::assertSame(3, $byQuarter['2026-Q3']['safe']);
        self::assertSame(2, $byQuarter['2026-Q4']['unsafe']);
    }

    public function testABlockIsOneRoadPartOfDayAndDayType(): void
    {
        $this->wait(['safe', 'safe', 'safe']);
        $this->wait(['safe', 'safe'], ['band' => 2]);
        self::assertSame(0, $this->pool->releaseOne(), 'two parts of the day are two blocks');

        $this->wait(['safe', 'safe'], ['dayType' => 'weekend']);
        $this->wait(['safe', 'safe'], ['way' => 99]);
        self::assertSame(0, $this->pool->releaseOne());
    }

    public function testAtMostOneBlockLeavesPerCall(): void
    {
        $this->wait(array_fill(0, 5, 'safe'));
        $this->wait(array_fill(0, 5, 'safe'), ['band' => 1]);

        self::assertSame(5, $this->pool->releaseOne());
        self::assertSame(5, $this->waiting(), 'the other block waits for the next mark');
        self::assertSame(5, $this->pool->releaseOne());
        self::assertSame(0, $this->waiting());
    }

    public function testARowMovedToAnotherBlockDoesNotOpenAndItsBlockIsPassedOver(): void
    {
        $this->wait(array_fill(0, 4, 'safe'));
        $this->wait(['safe'], ['band' => 1]);
        $keys = static::getContainer()->get(TrafficKeys::class);
        $this->db->executeStatement('UPDATE safety_pool SET block_key = ? WHERE block_key = ?',
            [$keys->safetyBlockKey(self::mark()), $keys->safetyBlockKey(self::mark(['band' => 1]))],
            [ParameterType::BINARY, ParameterType::BINARY]);

        $log = new SafetyPoolLog();
        $pool = new SafetyPool($this->db, $keys, $this->store, $log);

        self::assertSame(0, $pool->releaseOne());
        self::assertSame(5, $this->waiting(), 'nothing is released or deleted');
        self::assertCount(1, $log->warnings);
        self::assertStringNotContainsString('4521877', $log->warnings[0]);
    }

    public function testATrafficLineDoesNotOpenAsAMark(): void
    {
        $keys = static::getContainer()->get(TrafficKeys::class);

        self::assertNotSame($keys->encryptionKey(), $keys->encryptionKey(TrafficKeys::SAFETY_ENC), 'its own key');
        self::assertNotSame($keys->blockKey(['way' => 7, 'dir' => 'f', 'band' => 1, 'dayType' => 'workday']),
            $keys->safetyBlockKey(['way' => 7, 'band' => 1, 'dayType' => 'workday']));
    }

    public function testWaitingListsTheMarksDecrypted(): void
    {
        $this->wait(['safe']);

        self::assertSame([self::mark(['felt' => 'safe'])], $this->pool->waiting());
    }
}

/** Collects the pool's warnings. */
final class SafetyPoolLog extends AbstractLogger
{
    /** @var list<string> */
    public array $warnings = [];

    /** @param array<array-key, mixed> $context */
    #[\Override]
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->warnings[] = (string) $message;
    }
}
