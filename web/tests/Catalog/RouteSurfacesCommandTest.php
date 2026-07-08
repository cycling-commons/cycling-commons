<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:catalog:route-surfaces` recomputes every route's derived surface profile
 * (ops/dev backfill + post-harvest use) and reports the number of rows touched —
 * exactly as the importer does after a harvest.
 */
final class RouteSurfacesCommandTest extends KernelTestCase
{
    public function testRecomputesAndReportsTheCount(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $route = (new RecommendedRoute())->setName('Route via command')
            ->setGeom(json_encode(['type' => 'LineString', 'coordinates' => [[5.3, 50.0], [5.3, 50.009]]], \JSON_THROW_ON_ERROR))
            ->setDistanceM(1_000)->setAscentM(0)
            ->setState(ItemState::Unverified)->setSource(ItemSource::Auto)->setSourceRef('fx:cmd');
        $segment = (new Item())->setLetter('A')->setName('Command segment')
            ->setGeom(json_encode(['type' => 'LineString', 'coordinates' => [[5.3, 50.0], [5.3, 50.009]]], \JSON_THROW_ON_ERROR))
            ->setCountryCode('BE')->setState(ItemState::Unverified)->setSource(ItemSource::Osm)
            ->setSourceRef('way/cmd')->setAttributes(['surface' => 'Asphalt']);
        $em->persist($route);
        $em->persist($segment);
        $em->flush();
        $routeId = (int) $route->getId();

        $tester = new CommandTester((new Application(self::$kernel))->find('app:catalog:route-surfaces'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Recomputed surface profiles for 1 route', $tester->getDisplay());

        $em->clear();
        $reloaded = $em->find(RecommendedRoute::class, $routeId);
        self::assertNotNull($reloaded);
        self::assertArrayHasKey('surfaces', $reloaded->getAttributes());
        self::assertSame('Asphalt', $reloaded->getAttributes()['surfaces']['parts'][0]['surface']);
    }
}
