<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Links;

use App\Links\WebsiteVerdict;
use App\Links\WebsiteVerdictStore;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The verdict table (docs/specs/website-liveness.md section 4): one row per
 * host, read in bulk by the serve paths, a host never seen answering unknown.
 */
final class WebsiteVerdictStoreTest extends KernelTestCase
{
    private function db(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function store(): WebsiteVerdictStore
    {
        return static::getContainer()->get(WebsiteVerdictStore::class);
    }

    public function testARecordedVerdictIsReadBackInBulkAndAnUnseenHostIsUnknown(): void
    {
        $now = new \DateTimeImmutable('2026-10-15 10:00:00+00:00');
        $this->store()->record('www.chevrouheid.com', 'https://www.chevrouheid.com/', WebsiteVerdict::of(404, '<title>ConnectYourDomain Error | Wix.com</title>'), $now);
        $this->store()->record('example.test', 'https://example.test/', WebsiteVerdict::of(200, 'ok'), $now);

        $verdicts = $this->store()->verdictFor(['www.chevrouheid.com', 'example.test', 'never.test']);

        self::assertSame(['www.chevrouheid.com' => 'parked', 'example.test' => 'live', 'never.test' => 'unknown'], $verdicts);
        self::assertSame([], $this->store()->verdictFor([]));
    }

    public function testARecordIsDueAgainAfterItsVerdictsPeriod(): void
    {
        $now = new \DateTimeImmutable('2026-10-15 10:00:00+00:00');
        $this->store()->record('live.test', 'https://live.test/', WebsiteVerdict::of(200, 'ok'), $now);
        $this->store()->record('odd.test', 'https://odd.test/', WebsiteVerdict::failed('Operation timed out'), $now);

        $rows = $this->db()->fetchAllAssociative("SELECT host, verdict, next_check_at::date::text AS due FROM website_check WHERE host IN ('live.test', 'odd.test') ORDER BY host");

        self::assertSame([
            ['host' => 'live.test', 'verdict' => 'live', 'due' => '2026-11-14'],
            ['host' => 'odd.test', 'verdict' => 'unknown', 'due' => '2026-10-18'],
        ], $rows);
    }

    public function testRecordingAgainReplacesTheRow(): void
    {
        $this->store()->record('again.test', 'https://again.test/', WebsiteVerdict::of(500, ''), new \DateTimeImmutable('2026-10-01 00:00:00+00:00'));
        $this->store()->record('again.test', 'https://again.test/new', WebsiteVerdict::of(200, 'back'), new \DateTimeImmutable('2026-10-15 00:00:00+00:00'));

        $row = $this->db()->fetchAssociative("SELECT url, verdict, status FROM website_check WHERE host = 'again.test'");

        self::assertSame(['url' => 'https://again.test/new', 'verdict' => 'live', 'status' => 200], $row);
    }

    public function testFreshIsAVerdictWithinItsPeriodElseNull(): void
    {
        $now = new \DateTimeImmutable('2026-10-15 10:00:00+00:00');
        $this->store()->record('fresh.test', 'https://fresh.test/', WebsiteVerdict::of(200, 'ok'), $now);

        self::assertSame('live', $this->store()->fresh('fresh.test', $now->modify('+10 days')));
        self::assertNull($this->store()->fresh('fresh.test', $now->modify('+31 days')));
        self::assertNull($this->store()->fresh('nobody.test', $now));
    }
}
