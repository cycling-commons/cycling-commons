<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Route domain v1 (spec §4.1): a rider proposal is a RecommendedRoute row in
 * state `submitted` carrying the proposing rider in `proposed_by` (plain int
 * column, house style — no ORM relation). NULL for imported routes.
 */
final class RouteProposalPersistenceTest extends KernelTestCase
{
    public function testProposedByRoundTripsThroughTheDatabase(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $route = (new RecommendedRoute())
            ->setName('Test proposal · Condroz')
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(12000)->setAscentM(150)
            ->setState(ItemState::Submitted)->setSource(ItemSource::User)
            ->setSourceRef('user:test-roundtrip-0001')
            ->setAttributes(['difficulty' => 'Moderate'])
            ->setProposedBy(4242);
        $em->persist($route);
        $em->flush();
        $em->clear();

        $reloaded = $em->find(RecommendedRoute::class, $route->getId());
        self::assertSame(4242, $reloaded->getProposedBy());
        self::assertSame(ItemState::Submitted, $reloaded->getState());
    }

    public function testImportedRouteRoundTripsWithNullProposedBy(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Imported route: never carries a proposing rider (spec §4.1). Persisted
        // WITHOUT setProposedBy() — the nullable column must round-trip as null.
        $route = (new RecommendedRoute())
            ->setName('Imported · Ardenne')
            ->setGeom('{"type":"LineString","coordinates":[[5.6,50.2],[5.7,50.3]]}')
            ->setDistanceM(20000)->setAscentM(300)
            ->setState(ItemState::Unverified)->setSource(ItemSource::Auto)
            ->setSourceRef('fx:test-imported-null-0001')
            ->setAttributes(['difficulty' => 'Hard']);
        $em->persist($route);
        $em->flush();
        $em->clear();

        $reloaded = $em->find(RecommendedRoute::class, $route->getId());
        self::assertNull($reloaded->getProposedBy());
        self::assertSame(ItemState::Unverified, $reloaded->getState());
    }
}
