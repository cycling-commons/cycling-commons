<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Catalog\Entity\Region;
use App\Contribution\RegionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Spec §5.4: proposals resolve region_id at intake with the same
 * ST_Contains(region.geom, ST_PointOnSurface(route)) rule the importer uses.
 */
final class RegionResolverTest extends KernelTestCase
{
    public function testResolvesTheContainingRegionOrNull(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $resolver = static::getContainer()->get(RegionResolver::class);

        $region = (new Region());
        $region->setSlug('resolver-test-box')->setName('Resolver test box')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[5.0,50.0],[6.0,50.0],[6.0,51.0],[5.0,51.0],[5.0,50.0]]]]}');
        $em->persist($region);
        $em->flush();

        $inside = '{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}';
        $outside = '{"type":"LineString","coordinates":[[10.2,45.4],[10.3,45.5]]}';

        self::assertSame((int) $region->getId(), $resolver->resolve($inside));
        self::assertNull($resolver->resolve($outside));
    }
}
