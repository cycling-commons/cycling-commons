<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Traffic;

use App\Entity\User;
use App\Service\UserDeletionService;
use App\Traffic\TrafficStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An account's traffic codes go with the account; the totals stay, because
 * they hold nothing about anyone (docs/specs/traffic-measurements.md §4.7).
 * And the dump that reads the tables in clear exists in development only (§4.8).
 */
final class TrafficRiderDeletionTest extends KernelTestCase
{
    private static function line(int $way): array
    {
        return [
            'way' => $way, 'dir' => 'f', 'label' => 'r', 'slot' => 40, 'dayType' => 'workday', 'season' => 'autumn',
            'quarter' => '2026-Q4', 'day' => 20731, 'distanceM' => 900, 'timeS' => 150, 'passes' => 2,
            'avgSpeedKmh' => 21.6, 'carSpeedBins' => null,
        ];
    }

    public function testDeletingAnAccountRemovesItsRiderRowsAndKeepsTheTotals(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $leaving = (new User())->setEmail('leaving@example.com');
        $leaving->setPassword('x');
        $leaving->setDisplayName('Leaving');
        $staying = (new User())->setEmail('staying@example.com');
        $staying->setPassword('x');
        $staying->setDisplayName('Staying');
        $em->persist($leaving);
        $em->persist($staying);
        $em->flush();

        $store = $c->get(TrafficStore::class);
        foreach ([11, 12] as $way) {
            $store->addToCell(self::line($way));
            $store->addToRider((int) $leaving->getId(), self::line($way));
        }
        $store->addToCell(self::line(11));
        $store->addToRider((int) $staying->getId(), self::line(11));

        $c->get(UserDeletionService::class)->purge($leaving);

        self::assertCount(1, $store->riders(), "only the staying rider's row is left");
        self::assertCount(2, iterator_to_array($store->cells(), false), 'the totals stay');
    }

    public function testTheClearTextDumpIsNotACommandOutsideDevelopment(): void
    {
        $app = new Application(self::bootKernel());

        self::assertFalse($app->has('app:traffic:dump'));
    }
}
