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

        // A box in the open mid-Atlantic (~lng -30, lat 0) where no real
        // operational region will ever exist, so a seeded region can never
        // collide with this fixture under the resolver's ORDER BY r.id LIMIT 1.
        // The slug is randomised so parallel/repeated runs cannot clash even
        // without DAMA rollback.
        $region = (new Region());
        $region->setSlug('resolver-test-box-'.bin2hex(random_bytes(4)))->setName('Resolver test box')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-31.0,-1.0],[-29.0,-1.0],[-29.0,1.0],[-31.0,1.0],[-31.0,-1.0]]]]}');
        $em->persist($region);
        $em->flush();

        $inside = '{"type":"LineString","coordinates":[[-30.2,-0.4],[-30.3,0.5]]}';
        $outside = '{"type":"LineString","coordinates":[[-20.2,10.4],[-20.3,10.5]]}';

        self::assertSame((int) $region->getId(), $resolver->resolve($inside));
        self::assertNull($resolver->resolve($outside));

        // Explicit teardown so the fixture is dropped even without DAMA rollback.
        $em->remove($region);
        $em->flush();
    }

    public function testSmallestAreaWinsWhenRegionsOverlap(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $resolver = static::getContainer()->get(RegionResolver::class);

        // Two overlapping boxes in the empty mid-Atlantic. The BIG one is
        // persisted first (lower id), so a lower-id-wins ordering would pick it;
        // area_km2 must decide instead (map-and-search.md §4.5).
        $suffix = bin2hex(random_bytes(4));
        $big = (new Region())->setSlug('overlap-big-'.$suffix)->setName('Big')->setAreaKm2(400.0)
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-32.0,-2.0],[-28.0,-2.0],[-28.0,2.0],[-32.0,2.0],[-32.0,-2.0]]]]}');
        $small = (new Region())->setSlug('overlap-small-'.$suffix)->setName('Small')->setAreaKm2(4.0)
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-31.0,-1.0],[-29.0,-1.0],[-29.0,1.0],[-31.0,1.0],[-31.0,-1.0]]]]}');
        $em->persist($big);
        $em->persist($small);
        $em->flush();

        // PointOnSurface lands near (-30.05, 0), inside both boxes.
        $insideBoth = '{"type":"LineString","coordinates":[[-30.1,-0.1],[-30.0,0.1]]}';
        self::assertSame((int) $small->getId(), $resolver->resolve($insideBoth));

        $em->remove($big);
        $em->remove($small);
        $em->flush();
    }
}
