<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Tests\Auth;

use App\Catalog\BikeType;
use App\Catalog\RidingStyle;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for the User entity's canonical display name and
 * riding-preference accessors — no kernel, no database.
 */
final class UserEntityTest extends TestCase
{
    // ── displayNameCanonical ────────────────────────────────────────────────

    public function testSetDisplayNameMaintainsLowercasedCanonical(): void
    {
        $user = new User();
        $user->setDisplayName('Hanne V');

        self::assertSame('Hanne V', $user->getDisplayName());
        self::assertSame('hanne v', $user->getDisplayNameCanonical());
    }

    public function testCanonicalTrimsSurroundingWhitespace(): void
    {
        $user = new User();
        $user->setDisplayName("  Hanne V\t");

        self::assertSame('hanne v', $user->getDisplayNameCanonical());
    }

    public function testCanonicalLowercasesMultibyteCharacters(): void
    {
        $user = new User();
        $user->setDisplayName('ÉLISE ØSTERGÅRD');

        self::assertSame('élise østergård', $user->getDisplayNameCanonical());
    }

    public function testEmptyOrWhitespaceDisplayNameYieldsNullCanonical(): void
    {
        // An empty name means "no name yet": it must canonicalize to NULL so
        // unnamed rows (test fixtures, partial flows) never collide on the
        // unique index — Postgres ignores NULLs, and UniqueEntity's default
        // ignoreNull skips them too.
        self::assertNull((new User())->getDisplayNameCanonical());

        $user = new User();
        $user->setDisplayName('   ');
        self::assertNull($user->getDisplayNameCanonical());

        $user->setDisplayName('X');
        self::assertSame('x', $user->getDisplayNameCanonical());

        $user->setDisplayName('');
        self::assertNull($user->getDisplayNameCanonical());
    }

    // ── bikeTypes / ridingStyles ────────────────────────────────────────────

    public function testBikeTypesRoundTripAsEnums(): void
    {
        $user = new User();
        $user->setBikeTypes([BikeType::Road, BikeType::Gravel]);

        self::assertSame([BikeType::Road, BikeType::Gravel], $user->getBikeTypes());
    }

    public function testRidingStylesRoundTripAsEnums(): void
    {
        $user = new User();
        $user->setRidingStyles([RidingStyle::Bikepacking, RidingStyle::Urban]);

        self::assertSame([RidingStyle::Bikepacking, RidingStyle::Urban], $user->getRidingStyles());
    }

    public function testPreferencesDefaultToEmpty(): void
    {
        $user = new User();

        self::assertSame([], $user->getBikeTypes());
        self::assertSame([], $user->getRidingStyles());
    }

    public function testSettersDeduplicate(): void
    {
        $user = new User();
        $user->setBikeTypes([BikeType::Road, BikeType::Road, BikeType::Mtb]);

        self::assertSame([BikeType::Road, BikeType::Mtb], $user->getBikeTypes());
    }

    public function testUnknownStoredStringsAreSilentlyDropped(): void
    {
        // Spec: a future enum rename must never fatal a page render — unknown
        // stored values are dropped on read. Inject a stale value directly
        // into the persisted (private) array, as Doctrine hydration would.
        $user = new User();

        $prop = new \ReflectionProperty(User::class, 'bikeTypes');
        $prop->setValue($user, ['Road', 'Penny-farthing']);

        self::assertSame([BikeType::Road], $user->getBikeTypes());

        $prop = new \ReflectionProperty(User::class, 'ridingStyles');
        $prop->setValue($user, ['Freeride', 'Urban']);

        self::assertSame([RidingStyle::Urban], $user->getRidingStyles());
    }

    // ── base location (region-scoping-design.md §3) ─────────────────────────

    public function testBaseLocationIsCoarsenedAtWrite(): void
    {
        $u = new User();
        $u->setBaseLocation(50.467123456, 4.871987654, 'Namur');
        self::assertSame(50.47, $u->getBaseLat());
        self::assertSame(4.87, $u->getBaseLng());
        self::assertSame('Namur', $u->getBasePlace());
        self::assertTrue($u->hasBaseLocation());
    }

    public function testBaseRadiusClampsToBounds(): void
    {
        $u = new User();
        self::assertSame(User::BASE_RADIUS_DEFAULT, $u->getBaseRadiusKm());
        $u->setBaseRadiusKm(5);
        self::assertSame(User::BASE_RADIUS_MIN, $u->getBaseRadiusKm());
        $u->setBaseRadiusKm(999);
        self::assertSame(User::BASE_RADIUS_MAX, $u->getBaseRadiusKm());
        $u->setBaseRadiusKm(60);
        self::assertSame(60, $u->getBaseRadiusKm());
    }

    public function testClearBaseLocationResetsEverything(): void
    {
        $u = new User();
        $u->setBaseLocation(50.47, 4.87, 'Namur');
        $u->setBaseRadiusKm(80);
        $u->setBaseRegionIds([1, 24]);
        $u->setBaseCountryCodes(['BE']);
        $u->clearBaseLocation();
        self::assertFalse($u->hasBaseLocation());
        self::assertNull($u->getBaseLat());
        self::assertNull($u->getBasePlace());
        self::assertSame(User::BASE_RADIUS_DEFAULT, $u->getBaseRadiusKm());
        self::assertSame([], $u->getBaseRegionIds());
        self::assertSame([], $u->getBaseCountryCodes());
    }

    public function testBaseRegionIdsToleratesJunkAndDedupes(): void
    {
        $u = new User();
        $u->setBaseRegionIds([3, '7', 3, 0, -2, 'x']);
        self::assertSame([3, 7], $u->getBaseRegionIds());
        $u->setBaseCountryCodes(['be', 'NL', 'be', '', 'toolong']);
        self::assertSame(['BE', 'NL'], $u->getBaseCountryCodes());
    }
}
