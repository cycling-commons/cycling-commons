<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Vote;

use App\Vote\Countdown;
use PHPUnit\Framework\TestCase;

/** The ballot countdown's first value, the same steps as assets/js/countdown.js (owner 2026-10-03). */
final class CountdownTest extends TestCase
{
    /** @return array{key: string, params: array<string, int|string>} */
    private static function left(int $seconds): array
    {
        $deadline = new \DateTimeImmutable('2026-12-01T00:00:00+00:00');

        return Countdown::of($deadline->modify(sprintf('-%d seconds', $seconds)), $deadline);
    }

    public function testDaysThenHoursThenMinutesThenAClock(): void
    {
        self::assertSame(['key' => 'vote.cd_days', 'params' => ['%count%' => 58]], self::left(58 * 86400 + 3600));
        self::assertSame(['key' => 'vote.cd_days', 'params' => ['%count%' => 2]], self::left(172800));
        self::assertSame(['key' => 'vote.cd_hours', 'params' => ['%count%' => 47]], self::left(172799));
        self::assertSame(['key' => 'vote.cd_hm', 'params' => ['%h%' => 5, '%m%' => '12']], self::left(5 * 3600 + 12 * 60 + 40));
        self::assertSame(['key' => 'vote.cd_hms', 'params' => ['%time%' => '00:42:13']], self::left(42 * 60 + 13));
        self::assertSame(['key' => 'vote.cd_closed', 'params' => []], self::left(0));
    }
}
