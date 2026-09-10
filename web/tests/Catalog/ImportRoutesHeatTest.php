<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\HeatPoint;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\Region;
use App\Catalog\ItemState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportRoutesHeatTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function runImport(): CommandTester
    {
        $src = __DIR__.'/../fixtures/catalog';
        $dir = sys_get_temp_dir().'/catalog-routes-'.getmypid();
        @mkdir($dir, 0777, true);
        foreach (['region-square.geojson', 'routes.json', 'heat.json'] as $f) {
            copy($src.'/'.$f, $dir.'/'.$f);
        }
        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:catalog:import'));
        $tester->execute(['dir' => $dir]);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }

    public function testRoutesUpsertWithMembershipAndHeatReloads(): void
    {
        $this->runImport();

        $route = $this->em->getRepository(RecommendedRoute::class)->findOneBy(['sourceRef' => 'fx:route:test-loop']);
        self::assertNotNull($route);
        self::assertSame(12300, $route->getDistanceM());
        self::assertSame(ItemState::Unverified, $route->getState());
        // The harvest artifact carries season as a lowercase scalar ('summer');
        // the importer normalizes it to the canonical capitalized list shape
        // the proposal form writes, so serving/drawer never see two shapes.
        self::assertSame(['Summer'], $route->getAttributes()['season']);
        $region = $this->em->getRepository(Region::class)->findOneBy(['slug' => 'test-square']);
        self::assertNotNull($region);
        self::assertSame($region->getId(), $route->getRegionId());

        $heatPoints = $this->em->getRepository(HeatPoint::class)->findAll();
        self::assertCount(2, $heatPoints);
        $seasons = array_map(static fn (HeatPoint $p): ?string => $p->getSeason(), $heatPoints);
        sort($seasons);
        self::assertSame(['summer', 'winter'], $seasons); // fixture heat.json carries a season per point

        // Re-import: routes stay 1 (upsert), heat stays 2 (delete+reload, not append).
        $this->em->clear();
        $this->runImport();
        self::assertCount(1, $this->em->getRepository(RecommendedRoute::class)->findAll());
        self::assertCount(2, $this->em->getRepository(HeatPoint::class)->findAll());
    }

    public function testUpdatePreservesRouteState(): void
    {
        $this->runImport();
        $route = $this->em->getRepository(RecommendedRoute::class)->findOneBy(['sourceRef' => 'fx:route:test-loop']);
        self::assertNotNull($route);
        $route->setState(ItemState::Verified);
        $this->em->flush();
        $this->em->clear();

        $this->runImport();
        $route = $this->em->getRepository(RecommendedRoute::class)->findOneBy(['sourceRef' => 'fx:route:test-loop']);
        self::assertNotNull($route);
        self::assertSame(ItemState::Verified, $route->getState()); // upsert never touches state
    }
}
