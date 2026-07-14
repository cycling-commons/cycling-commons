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
}
