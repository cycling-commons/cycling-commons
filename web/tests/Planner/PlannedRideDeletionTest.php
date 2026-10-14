<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Planner;

use App\Entity\User;
use App\Planner\PlannedRides;
use App\Planner\RideInput;
use App\Service\UserDeletionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A saved ride is personal data and goes with the account
 * (docs/specs/route-planner.md section 7): nothing public derives from it,
 * so there is nothing to keep and nothing to anonymise.
 */
final class PlannedRideDeletionTest extends KernelTestCase
{
    use RideFixtures;

    public function testPurgingAnAccountRemovesItsRidesAndNobodyElses(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $db = $c->get(Connection::class);

        $leaving = (new User())->setEmail('leaving-rides@test.test');
        $leaving->setPassword('x');
        $staying = (new User())->setEmail('staying-rides@test.test');
        $staying->setPassword('x');
        $em->persist($leaving);
        $em->persist($staying);
        $em->flush();

        $rides = $c->get(PlannedRides::class);
        $input = RideInput::parse(self::body($db));
        self::assertNotNull($input);
        $rides->save($leaving, $input);
        $rides->save($leaving, $input);
        $kept = $rides->save($staying, $input)->getId();
        $gone = (int) $leaving->getId();

        $c->get(UserDeletionService::class)->purge($leaving);
        $em->flush();

        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM planned_ride WHERE user_id = ?', [$gone]));
        self::assertSame($kept, (int) $db->fetchOne('SELECT id FROM planned_ride WHERE user_id = ?', [(int) $staying->getId()]));
    }
}
