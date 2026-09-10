<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Catalog\Entity\Region;
use App\Contribution\SpatialResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * map-and-search.md §4.5: SpatialResolver is the
 * item-submission twin of RegionResolver and must apply the same
 * smallest-area tie-break, otherwise a point inside both an operational L4
 * region and its containing infrastructure-only L2 country outline can
 * anchor to the L2 row (live repro before the fix: points in Bavaria/NRW
 * resolved to the `germany` row instead of the containing Land).
 */
final class SpatialResolverTest extends KernelTestCase
{
    public function testSmallestAreaRegionWinsOverAContainingLargerOne(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $resolver = static::getContainer()->get(SpatialResolver::class);

        // Two overlapping boxes in the empty mid-Atlantic, both country code
        // 'ZZ'. The BIG one (standing in for an L2 infrastructure outline) is
        // persisted first (lower id) and is larger by area_km2, so a
        // no-ORDER-BY or lower-id-wins query would pick it; the fix must pick
        // the smaller, containing region instead.
        $suffix = bin2hex(random_bytes(4));
        $big = (new Region())->setSlug('spatial-overlap-big-'.$suffix)->setName('Big')
            ->setCountryCode('ZZ')->setAreaKm2(400.0)
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-32.0,-2.0],[-28.0,-2.0],[-28.0,2.0],[-32.0,2.0],[-32.0,-2.0]]]]}');
        $small = (new Region())->setSlug('spatial-overlap-small-'.$suffix)->setName('Small')
            ->setCountryCode('ZZ')->setAreaKm2(4.0)
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-31.0,-1.0],[-29.0,-1.0],[-29.0,1.0],[-31.0,1.0],[-31.0,-1.0]]]]}');
        $em->persist($big);
        $em->persist($small);
        $em->flush();

        $result = $resolver->resolve(0.0, -30.0);

        self::assertSame($small->getId(), $result['regionId'], 'the smaller, containing region must win over the larger enclosing one');
        self::assertSame('ZZ', $result['countryCode']);

        $em->remove($big);
        $em->remove($small);
        $em->flush();
    }
}
