<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Vote;

use App\Catalog\Season;
use App\Vote\Hemisphere;
use App\Vote\Round;
use PHPUnit\Framework\TestCase;

final class RoundTest extends TestCase
{
    private static function at(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso);
    }

    public function testANorthernSpringRound(): void
    {
        $r = Round::containing(self::at('2027-04-10T12:00:00+00:00'), Hemisphere::North);
        self::assertSame(Season::Spring, $r->season);
        self::assertSame(2027, $r->year);
        self::assertSame('2027-03-01', $r->startDate());
        self::assertSame('2027-03-01T00:00:00+00:00', $r->opensAt()->format(\DATE_ATOM));
        self::assertSame('2027-06-01T00:00:00+00:00', $r->closesAt()->format(\DATE_ATOM));
        self::assertSame('2027-05-31', $r->lastDay()->format('Y-m-d'));
        self::assertSame('2027', $r->yearLabel());
    }

    public function testJanuaryAndFebruaryBelongToTheRoundThatStartedInDecember(): void
    {
        $jan = Round::containing(self::at('2027-01-15T08:00:00+00:00'), Hemisphere::North);
        self::assertSame(Season::Winter, $jan->season);
        self::assertSame(2026, $jan->year);
        self::assertSame('2026-12-01', $jan->startDate());
        self::assertSame('2026-27', $jan->yearLabel());

        $leap = Round::containing(self::at('2028-02-29T23:59:59+00:00'), Hemisphere::North);
        self::assertSame('2027-12-01', $leap->startDate());
        self::assertSame('2028-03-01T00:00:00+00:00', $leap->closesAt()->format(\DATE_ATOM));
    }

    public function testTheSouthernHemisphereHasTheOtherSeason(): void
    {
        self::assertSame(Season::Autumn, Round::containing(self::at('2027-04-10T12:00:00+00:00'), Hemisphere::South)->season);

        $summer = Round::containing(self::at('2027-01-15T08:00:00+00:00'), Hemisphere::South);
        self::assertSame(Season::Summer, $summer->season);
        self::assertSame('2026-12-01', $summer->startDate());
        self::assertSame('2026-27', $summer->yearLabel());

        self::assertSame('2026-09-01', Round::of(Season::Spring, 2026, Hemisphere::South)->startDate());
    }

    public function testBoundariesAreUtcInstants(): void
    {
        // 00:30 on 1 March in Brussels is 23:30 on 28 February in UTC.
        self::assertSame(Season::Winter, Round::containing(self::at('2027-03-01T00:30:00+01:00'), Hemisphere::North)->season);
        self::assertSame(Season::Spring, Round::containing(self::at('2027-03-01T00:00:00+00:00'), Hemisphere::North)->season);
    }

    public function testOpenAndClosedAtTheExactInstant(): void
    {
        $spring = Round::of(Season::Spring, 2027, Hemisphere::North);
        self::assertTrue($spring->isOpenAt(self::at('2027-05-31T23:59:59+00:00')));
        self::assertFalse($spring->isOpenAt(self::at('2027-06-01T00:00:00+00:00')));
        self::assertTrue($spring->hasClosedBy(self::at('2027-06-01T00:00:00+00:00')));
        self::assertFalse($spring->hasClosedBy(self::at('2027-05-31T23:59:59+00:00')));
    }

    public function testTheLatestStartedRoundOfASeason(): void
    {
        $now = self::at('2026-10-02T13:39:00+00:00');
        self::assertSame('2025-12-01', Round::latestStarted(Season::Winter, Hemisphere::North, $now)->startDate());
        self::assertSame('2026-09-01', Round::latestStarted(Season::Autumn, Hemisphere::North, $now)->startDate());
        self::assertSame('2026-03-01', Round::latestStarted(Season::Spring, Hemisphere::North, $now)->startDate());
        self::assertSame('2026-09-01', Round::latestStarted(Season::Spring, Hemisphere::South, $now)->startDate());
        self::assertSame('2027-03-01', Round::latestStarted(Season::Spring, Hemisphere::North, self::at('2028-02-29T12:00:00+00:00'))->startDate());
    }

    public function testTheSameSeasonAYearBefore(): void
    {
        $before = Round::of(Season::Spring, 2028, Hemisphere::North)->yearBefore();
        self::assertSame('2027-03-01', $before->startDate());
        self::assertSame(Hemisphere::North, $before->hemisphere);
    }

    public function testAStoredRoundComesBackWithItsHemisphere(): void
    {
        self::assertSame(Hemisphere::North, Round::fromStored('winter', '2026-12-01')->hemisphere);
        self::assertSame(Hemisphere::South, Round::fromStored('summer', '2026-12-01')->hemisphere);
        self::assertSame(Hemisphere::South, Round::fromStored('autumn', '2027-03-01')->hemisphere);
        self::assertSame(2026, Round::fromStored('winter', '2026-12-01')->year);
    }

    public function testAStoredRoundThatCannotExistIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Round::fromStored('spring', '2026-12-01');
    }

    public function testTheMiddleLatitudeDecidesTheHemisphere(): void
    {
        self::assertSame(Hemisphere::South, Hemisphere::ofLatitude(-33.9));
        self::assertSame(Hemisphere::North, Hemisphere::ofLatitude(50.5));
        self::assertSame(Hemisphere::North, Hemisphere::ofLatitude(0.0));
    }
}
