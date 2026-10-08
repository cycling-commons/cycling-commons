<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Traffic;

use App\Entity\User;
use App\Service\UserDeletionService;
use App\Traffic\TrafficStore;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * No traffic row names a rider, so deleting an account has nothing to find:
 * the totals stay as they were
 * (docs/specs/traffic-measurements.md §4.7). And the dump that reads the
 * tables in clear exists in development only (§4.8).
 */
final class TrafficAccountDeletionTest extends KernelTestCase
{
    public function testDeletingAnAccountLeavesTheTrafficRowsAsTheyWere(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $leaving = (new User())->setEmail('leaving-traffic@example.com');
        $leaving->setPassword('x');
        $leaving->setDisplayName('Leaving');
        $em->persist($leaving);
        $em->flush();

        $store = $c->get(TrafficStore::class);
        $store->addToTotal([
            'way' => 11, 'region' => null, 'dir' => 'f', 'label' => 'r', 'band' => 2, 'dayType' => 'workday', 'quarter' => '2026-Q4', 'dayGroup' => 3,
            'distanceM' => 900, 'timeS' => 150, 'passes' => 2, 'nearby' => 0, 'avgSpeedKmh' => 21.6, 'carSpeedBins' => null,
        ]);
        $db = $c->get(Connection::class);
        $before = $db->fetchAllAssociative('SELECT * FROM traffic_total');

        $c->get(UserDeletionService::class)->purge($leaving);

        self::assertSame($before, $db->fetchAllAssociative('SELECT * FROM traffic_total'));
    }

    public function testTheClearTextDumpIsNotACommandOutsideDevelopment(): void
    {
        $app = new Application(self::bootKernel());

        self::assertFalse($app->has('app:traffic:dump'));
    }
}
