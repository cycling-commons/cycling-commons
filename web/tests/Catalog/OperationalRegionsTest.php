<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\Region;
use App\Catalog\RegionRegistryProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OperationalRegionsTest extends KernelTestCase
{
    private const GEOM = '{"type":"MultiPolygon","coordinates":[[[[4,50],[5,50],[5,51],[4,51],[4,50]]]]}';

    private function seed(string $slug, string $cc, ?int $level): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new Region())->setSlug($slug)->setName(ucfirst($slug))
            ->setCountryCode($cc)->setAdminLevel($level)->setGeom(self::GEOM)->setAreaKm2(100.0));
        $em->flush();
    }

    public function testL2InfrastructureRowIsHiddenWhenADeeperLevelExists(): void
    {
        self::bootKernel();
        $this->seed('wallonia-t', 'BE', 4);
        $this->seed('belgium-t', 'BE', 2);

        $slugs = array_column(static::getContainer()->get(RegionRegistryProvider::class)->all(), 'slug');
        self::assertContains('wallonia-t', $slugs);
        self::assertNotContains('belgium-t', $slugs, 'the L2 country outline must never surface as a region');
    }

    public function testCountryOperatingAtL2StaysVisible(): void
    {
        self::bootKernel();
        $this->seed('luxembourg-t', 'LU', 2);

        $slugs = array_column(static::getContainer()->get(RegionRegistryProvider::class)->all(), 'slug');
        self::assertContains('luxembourg-t', $slugs, 'a lone L2 row IS the operational region (LU)');
    }

    public function testNullAdminLevelSingleRowCountryStaysVisible(): void
    {
        self::bootKernel();
        $this->seed('bayern-t', 'DE', null);

        $slugs = array_column(static::getContainer()->get(RegionRegistryProvider::class)->all(), 'slug');
        self::assertContains('bayern-t', $slugs, 'NULL admin_level fixtures must not be filtered out');
    }
}
