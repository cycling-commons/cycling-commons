<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Legal;

use App\Entity\User;
use App\Service\AdminDashboardStats;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The admin dashboard counts the accounts per privacy notice version they last
 * saw, "none yet" included (docs/specs/privacy-notice.md).
 */
final class PrivacyVersionCountTest extends KernelTestCase
{
    public function testAccountsAreCountedPerVersionSeen(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $before = static::getContainer()->get(AdminDashboardStats::class)->collect()['privacyVersions'];
        foreach ([2, 2, 1, null] as $i => $seen) {
            $u = (new User())->setEmail('pvc-'.$i.'-'.bin2hex(random_bytes(3)).'@example.com');
            $u->setPassword('x');
            $u->setDisplayName('Counted');
            $u->setPrivacyVersionSeen($seen);
            $em->persist($u);
        }
        $em->flush();

        $after = static::getContainer()->get(AdminDashboardStats::class)->collect()['privacyVersions'];
        $count = static fn (array $rows, ?int $v): int => array_sum(array_map(static fn (array $r): int => $r['version'] === $v ? $r['accounts'] : 0, $rows));
        self::assertSame($count($before, 2) + 2, $count($after, 2));
        self::assertSame($count($before, 1) + 1, $count($after, 1));
        self::assertSame($count($before, null) + 1, $count($after, null));
    }
}
