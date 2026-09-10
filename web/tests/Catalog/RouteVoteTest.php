<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\BikeType;
use App\Catalog\Entity\RouteVote;
use App\Catalog\Season;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RouteVoteTest extends KernelTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testPersistsAndReadsBack(): void
    {
        self::bootKernel();
        $em = $this->em();
        $vote = new RouteVote(200, 5, Season::Spring, BikeType::Gravel);
        $em->persist($vote);
        $em->flush();
        $em->clear();

        $found = $em->find(RouteVote::class, $vote->getId());
        self::assertSame(Season::Spring, $found->getSeason());
        self::assertSame(BikeType::Gravel, $found->getBikeType());
    }

    public function testOneVotePerUserPerRoutePerSeason(): void
    {
        self::bootKernel();
        $em = $this->em();
        $em->persist(new RouteVote(201, 6, Season::Summer, BikeType::Road));
        $em->flush();

        $this->expectException(UniqueConstraintViolationException::class);
        // Same (route,user,season) even with a different bike → rejected.
        $em->persist(new RouteVote(201, 6, Season::Summer, BikeType::Mtb));
        $em->flush();
    }

    public function testDifferentSeasonSameUserRouteIsAllowed(): void
    {
        self::bootKernel();
        $em = $this->em();
        $em->persist(new RouteVote(202, 6, Season::Summer, BikeType::Road));
        $em->persist(new RouteVote(202, 6, Season::Autumn, BikeType::Road));
        $em->flush();

        self::assertSame(2, (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM route_vote WHERE route_id = 202 AND user_id = 6',
        ));
    }
}
