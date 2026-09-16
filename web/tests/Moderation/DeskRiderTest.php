<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\RiderPseudonym;
use App\Entity\User;
use App\Moderation\DeskRider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DeskRiderTest extends TestCase
{
    public function testAPublicProfileIsTheDisplayNameLinkedToTheProfile(): void
    {
        self::assertSame(['name' => 'Route Rider', 'uuid' => 'abc'], DeskRider::of(18, ' Route Rider ', true, 'abc'));
    }

    public function testAPrivateProfileIsThePseudonymWithoutALink(): void
    {
        self::assertSame(['name' => RiderPseudonym::for(18), 'uuid' => null], DeskRider::of(18, 'Route Rider', false, 'abc'));
    }

    public function testAPublicProfileWithoutADisplayNameIsThePseudonymWithoutALink(): void
    {
        self::assertSame(['name' => RiderPseudonym::for(18), 'uuid' => null], DeskRider::of(18, '  ', true, 'abc'));
        self::assertSame(['name' => RiderPseudonym::for(18), 'uuid' => null], DeskRider::of(18, null, true, 'abc'));
    }

    public function testAPublicProfileWithoutAUuidIsNamedButNotLinked(): void
    {
        self::assertSame(['name' => 'Route Rider', 'uuid' => null], DeskRider::of(18, 'Route Rider', true, null));
    }

    public function testAColleagueIsAlwaysNamedAndLinkedOnlyWithAPublicProfile(): void
    {
        // Curators are named to each other on the desks (the rulebook says so); the link follows the profile setting.
        self::assertSame(['name' => 'Desk Curator', 'uuid' => 'abc'], DeskRider::colleague(' Desk Curator ', true, 'abc'));
        self::assertSame(['name' => 'Desk Curator', 'uuid' => null], DeskRider::colleague('Desk Curator', false, 'abc'));
        self::assertNull(DeskRider::colleague(null, true, 'abc'));
        self::assertNull(DeskRider::colleague('  ', false, null));
    }

    public function testAUserEntityIsNamedByTheSameRule(): void
    {
        $uuid = Uuid::v7();
        $public = $this->user(18, 'Route Rider', true, $uuid);
        self::assertSame(['name' => 'Route Rider', 'uuid' => $uuid->toRfc4122()], DeskRider::ofUser($public));
        self::assertSame(['name' => RiderPseudonym::for(18), 'uuid' => null], DeskRider::ofUser($this->user(18, 'Route Rider', false, $uuid)));
        self::assertSame(['name' => 'Route Rider', 'uuid' => null], DeskRider::colleagueUser($this->user(18, 'Route Rider', false, $uuid)));
    }

    private function user(int $id, string $name, bool $public, Uuid $uuid): User
    {
        $user = new User();
        $user->setDisplayName($name);
        $user->setPublicProfile($public);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        (new \ReflectionProperty(User::class, 'uuid'))->setValue($user, $uuid);

        return $user;
    }
}
