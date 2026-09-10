<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Admin;

use App\Entity\User;
use App\Service\AdminDashboardStats;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DashboardStatsTest extends KernelTestCase
{
    /** @param list<string> $roles */
    private function user(EntityManagerInterface $em, string $email, array $roles = [], bool $verified = true): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setDisplayName(strstr($email, '@', true) ?: $email);
        $u->setPassword('x');
        $u->setRoles($roles);
        $u->setEmailVerified($verified);
        $em->persist($u);

        return $u;
    }

    public function testCollectCountsByCategory(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);

        $this->user($em, 'admin@example.com', ['ROLE_ADMIN']);
        $this->user($em, 'cur@example.com', ['ROLE_CURATOR']);
        $this->user($em, 'plain@example.com', []);
        $unv = $this->user($em, 'unv@example.com', [], verified: false);
        $locked = $this->user($em, 'locked@example.com', []);
        $locked->setLockedUntil(new \DateTimeImmutable('+1 hour'));
        $pending = $this->user($em, 'pending@example.com', []);
        $pending->setDeletionRequestedAt(new \DateTimeImmutable());
        $em->flush();
        \assert($unv instanceof User);

        /** @var AdminDashboardStats $stats */
        $stats = $c->get(AdminDashboardStats::class);
        $s = $stats->collect();

        self::assertSame(6, $s['members']);
        self::assertSame(1, $s['admins']);
        self::assertSame(1, $s['curators']);
        self::assertSame(1, $s['unverified']);
        self::assertSame(1, $s['locked']);
        self::assertSame(1, $s['pendingRemoval']);
        self::assertLessThanOrEqual(10, \count($s['recent']));
    }
}
