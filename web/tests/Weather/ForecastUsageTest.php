<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Weather;

use App\Weather\ForecastUsage;
use Doctrine\DBAL\Connection;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * What the planner's weather costs (route-planner.md section 8): asks counted
 * per minute in forecast_usage, read back as where Open-Meteo's limits stand
 * now and as days and hours with the empty ones filled, all in UTC; a failed
 * write is logged and never stands in the forecast's way.
 */
final class ForecastUsageTest extends KernelTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->db->executeStatement('DELETE FROM forecast_usage');
    }

    private function usage(string $at): ForecastUsage
    {
        return new ForecastUsage($this->db, new NullLogger(), new MockClock($at));
    }

    public function testAsksAddUpPerMinuteAndNowReadsTheLimitsInUtc(): void
    {
        $at = $this->usage('2026-10-23 09:30:20 UTC');
        $at->asked(true);
        $at->asked(true);
        $at->asked(false, 1.5);
        $at->asked(false, 1.5, failed: true, refused: true);
        $at->limited();
        // 01:10 in Brussels is 23:10 UTC the day before: yesterday, as Open-Meteo counts.
        $this->usage('2026-10-23 01:10:00 Europe/Brussels')->asked(false, 1.5);
        $this->usage('2026-10-23 08:05:00 UTC')->asked(false, 1.5);

        self::assertSame(3, (int) $this->db->fetchOne('SELECT COUNT(*) FROM forecast_usage'), 'one row per minute with any ask');
        self::assertSame([
            'hour' => 3.0, 'day' => 4.5, 'month' => 6.0, 'peakMinute' => 3.0,
            'asked' => 5, 'hits' => 2, 'calls' => 3, 'failures' => 1, 'refused' => 1, 'limited' => 1,
        ], $this->usage('2026-10-23 09:59:00 UTC')->now());
    }

    public function testDaysAndHoursComeBackOldestFirstWithTheEmptyOnesFilled(): void
    {
        $this->usage('2026-10-23 09:30:00 UTC')->asked(false, 1.5);
        $this->usage('2026-10-23 09:31:00 UTC')->asked(true);
        $this->usage('2026-10-21 12:00:00 UTC')->limited();

        $now = $this->usage('2026-10-23 10:15:00 UTC');
        $days = $now->days(3);
        self::assertSame(['2026-10-21', '2026-10-22', '2026-10-23'], array_column($days, 'at'));
        self::assertSame(['at' => '2026-10-21', 'asked' => 0, 'hits' => 0, 'calls' => 0, 'weight' => 0.0, 'failures' => 0, 'refused' => 0, 'limited' => 1], $days[0]);
        self::assertSame(0, $days[1]['asked'], 'a day without asks is a row of zeros');
        self::assertSame(['at' => '2026-10-23', 'asked' => 2, 'hits' => 1, 'calls' => 1, 'weight' => 1.5, 'failures' => 0, 'refused' => 0, 'limited' => 0], $days[2]);

        $hours = $now->hours(3);
        self::assertSame(['2026-10-23 08:00', '2026-10-23 09:00', '2026-10-23 10:00'], array_column($hours, 'at'));
        self::assertSame([0.0, 1.5, 0.0], array_column($hours, 'weight'));
    }

    public function testAFailedWriteIsLoggedAndTheForecastGoesOn(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('executeStatement')->willThrowException(new \RuntimeException('no table'));
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $logged = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = (string) $message;
            }
        };

        (new ForecastUsage($db, $logger, new MockClock('2026-10-23 09:30:00 UTC')))->asked(true);

        self::assertSame(['forecast usage not recorded'], $logger->logged);
    }
}
