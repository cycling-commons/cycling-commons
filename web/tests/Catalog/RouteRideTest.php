<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\BikeType;
use App\Catalog\Entity\RouteRide;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RouteRideTest extends KernelTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testPersistsAndReadsBack(): void
    {
        self::bootKernel();
        $em = $this->em();
        $ride = new RouteRide(100, 7, BikeType::Gravel);
        $em->persist($ride);
        $em->flush();
        $em->clear();

        $found = $em->find(RouteRide::class, $ride->getId());
        self::assertNotNull($found);
        self::assertSame(100, $found->getRouteId());
        self::assertSame(7, $found->getUserId());
        self::assertSame(BikeType::Gravel, $found->getBikeType());
    }

    public function testOneRidePerUserPerRouteIsEnforced(): void
    {
        self::bootKernel();
        $em = $this->em();
        $em->persist(new RouteRide(101, 8, BikeType::Road));
        $em->flush();

        $this->expectException(UniqueConstraintViolationException::class);
        $em->persist(new RouteRide(101, 8, BikeType::Mtb));
        $em->flush();
    }
}
