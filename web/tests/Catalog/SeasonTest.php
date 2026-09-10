<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Season;
use PHPUnit\Framework\TestCase;

final class SeasonTest extends TestCase
{
    /** @return iterable<string, array{string, Season}> */
    public static function months(): iterable
    {
        yield 'March → spring' => ['2026-03-01', Season::Spring];
        yield 'May → spring' => ['2026-05-31', Season::Spring];
        yield 'June → summer' => ['2026-06-01', Season::Summer];
        yield 'August → summer' => ['2026-08-31', Season::Summer];
        yield 'September → autumn' => ['2026-09-01', Season::Autumn];
        yield 'November → autumn' => ['2026-11-30', Season::Autumn];
        yield 'December → winter' => ['2026-12-01', Season::Winter];
        yield 'January → winter' => ['2026-01-15', Season::Winter];
        yield 'February → winter' => ['2026-02-28', Season::Winter];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('months')]
    public function testCurrentMapsMonthToNorthernHemisphereSeason(string $date, Season $expected): void
    {
        self::assertSame($expected, Season::current(new \DateTimeImmutable($date)));
    }

    public function testValuesAreTheFourLowercaseSeasons(): void
    {
        self::assertSame(['spring', 'summer', 'autumn', 'winter'], Season::values());
    }
}
