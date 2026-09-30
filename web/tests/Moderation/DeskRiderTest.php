<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Entity\User;
use App\Moderation\DeskRider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DeskRiderTest extends TestCase
{
    public function testAPublicProfileIsTheDisplayNameLinkedToTheProfile(): void
    {
        self::assertSame(['name' => 'Route Rider', 'uuid' => 'abc'], DeskRider::of(18, 'k7m2x9qp', ' Route Rider ', true, 'abc'));
    }

    public function testAPrivateProfileIsThePseudonymWithoutALink(): void
    {
        self::assertSame(['name' => 'rider#k7m2x9qp', 'uuid' => null], DeskRider::of(18, 'k7m2x9qp', 'Route Rider', false, 'abc'));
    }

    public function testAPublicProfileWithoutADisplayNameIsThePseudonymWithoutALink(): void
    {
        self::assertSame(['name' => 'rider#k7m2x9qp', 'uuid' => null], DeskRider::of(18, 'k7m2x9qp', '  ', true, 'abc'));
        self::assertSame(['name' => 'rider#k7m2x9qp', 'uuid' => null], DeskRider::of(18, 'k7m2x9qp', null, true, 'abc'));
    }

    public function testARemovedAccountIsTheHandleDerivedFromItsId(): void
    {
        // No row, no stored pseudonym: the handle every rider had before pseudonyms were stored.
        self::assertSame(['name' => 'rider#'.substr(hash('crc32b', 'cc-sub-18'), 0, 4), 'uuid' => null], DeskRider::of(18, null, null, null, null));
    }

    public function testAPublicProfileWithoutAUuidIsNamedButNotLinked(): void
    {
        self::assertSame(['name' => 'Route Rider', 'uuid' => null], DeskRider::of(18, 'k7m2x9qp', 'Route Rider', true, null));
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
        $private = $this->user(18, 'Route Rider', false, $uuid);
        self::assertSame(['name' => 'rider#'.$private->getPseudonym(), 'uuid' => null], DeskRider::ofUser($private));
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
