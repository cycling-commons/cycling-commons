<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\ConfirmationFreshness;
use App\Catalog\ItemType;
use App\Settings\SettingsRegistry;
use App\Tests\Settings\FakeSettings;
use PHPUnit\Framework\TestCase;

/**
 * Staleness, and the three rules that stop it meaning nothing.
 *
 * The failure this feature has to survive is not a wrong date: it is that six
 * months after launch most of the map is orange, and an orange that means
 * "everything" means nothing. Every test here is one of the rules that keeps
 * the signal narrow, so a later change that widens it fails by name rather
 * than by somebody noticing the map has gone orange.
 */
final class ConfirmationFreshnessTest extends TestCase
{
    private function freshness(int $months = 6): ConfirmationFreshness
    {
        return new ConfirmationFreshness(new FakeSettings([
            SettingsRegistry::MAP_CONFIRMATION_STALE_MONTHS => $months,
        ]));
    }

    /** Rule 1: never confirmed is UNVERIFIED, a different state with its own signal. */
    public function testAnItemNobodyHasConfirmedIsNotStale(): void
    {
        self::assertNull($this->freshness()->state(
            ItemType::WaterFood,
            null,
            new \DateTimeImmutable('2026-08-16'),
        ), 'ageing something that was never fresh would paint every harvested row orange on day one');
    }

    /** Rule 2: the built world ages, the landscape does not. */
    public function testOnlyLettersWhoseConfirmationsGoOffTakeAState(): void
    {
        $f = $this->freshness();
        $long = new \DateTimeImmutable('2020-01-01');
        $now = new \DateTimeImmutable('2026-08-16');

        foreach ([ItemType::WaterFood, ItemType::BikeServices, ItemType::Hazards,
            ItemType::PublicToilets, ItemType::Shelter, ItemType::WhereToSleep,
            ItemType::GettingThere, ItemType::RoadSurface] as $ages) {
            self::assertSame(ConfirmationFreshness::STALE, $f->state($ages, $long, $now),
                $ages->value.' is built or reported, and goes off');
        }
        foreach ([ItemType::Climbs, ItemType::ScenicViews, ItemType::HistoryCulture] as $never) {
            self::assertNull($f->state($never, $long, $now),
                $never->value.' does not stop being what it is');
        }
    }

    /** Rule 3: a check buys another full window, rather than adding to a tally. */
    public function testOneConfirmationResetsTheClock(): void
    {
        $f = $this->freshness();
        $now = new \DateTimeImmutable('2026-08-16');
        // Confirmed seven months ago and never since: stale.
        self::assertSame(ConfirmationFreshness::STALE,
            $f->state(ItemType::WaterFood, new \DateTimeImmutable('2026-01-16'), $now));
        // One rider checks it today; the state comes back to fresh, not to
        // "stale but with two confirmations".
        self::assertSame(ConfirmationFreshness::FRESH,
            $f->state(ItemType::WaterFood, $now, $now));
    }

    /** The three bands, on the boundaries where an off-by-one would live. */
    public function testTheBandsSplitAtHalfTheWindowAndAtTheWindow(): void
    {
        $f = $this->freshness(6);
        $now = new \DateTimeImmutable('2026-08-16');
        $at = static fn (string $when): \DateTimeImmutable => new \DateTimeImmutable($when);

        self::assertSame(ConfirmationFreshness::FRESH, $f->state(ItemType::WaterFood, $at('2026-06-16'), $now));
        // Exactly three months old is already ageing: the band is closed at its
        // start, so a boundary date never falls between two states.
        self::assertSame(ConfirmationFreshness::AGEING, $f->state(ItemType::WaterFood, $at('2026-05-16'), $now));
        self::assertSame(ConfirmationFreshness::AGEING, $f->state(ItemType::WaterFood, $at('2026-02-17'), $now));
        self::assertSame(ConfirmationFreshness::STALE, $f->state(ItemType::WaterFood, $at('2026-02-16'), $now));
    }

    /**
     * The window is a dial, so the whole scale moves with it. A one-month
     * setting must still produce three usable bands rather than collapsing
     * ageing to zero width - which is what an unguarded intdiv(1, 2) would do.
     */
    public function testTheWindowIsASettingAndTheBandsFollowIt(): void
    {
        $now = new \DateTimeImmutable('2026-08-16');
        $lastMonth = new \DateTimeImmutable('2026-07-16');

        self::assertSame(ConfirmationFreshness::FRESH,
            $this->freshness(24)->state(ItemType::WaterFood, $lastMonth, $now));
        self::assertSame(ConfirmationFreshness::STALE,
            $this->freshness(1)->state(ItemType::WaterFood, $lastMonth, $now));
        // A one-month window still has an ageing band rather than jumping
        // fresh -> stale: intdiv(1, 2) is 0, and max(1, ...) is why.
        self::assertSame(ConfirmationFreshness::AGEING,
            $this->freshness(2)->state(ItemType::WaterFood, $lastMonth, $now));
    }

    /** The list query selects on a date, not on a state computed per row. */
    public function testTheStaleBoundaryIsAvailableAsADate(): void
    {
        self::assertSame(
            '2026-02-16',
            $this->freshness(6)->staleBefore(new \DateTimeImmutable('2026-08-16'))->format('Y-m-d'),
        );
    }
}
