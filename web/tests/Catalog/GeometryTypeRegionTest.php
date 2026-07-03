<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\Region;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GeometryTypeRegionTest extends KernelTestCase
{
    public function testRegionGeometryRoundTripsThroughPostgis(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $square = '{"type":"MultiPolygon","coordinates":[[[[4.0,50.0],[5.0,50.0],[5.0,51.0],[4.0,51.0],[4.0,50.0]]]]}';
        $region = (new Region())->setSlug('test-square')->setName('Test Square')->setGeom($square)->setAreaKm2(1234.5);
        $em->persist($region);
        $em->flush();
        $em->clear();

        $reloaded = $em->getRepository(Region::class)->findOneBy(['slug' => 'test-square']);
        self::assertNotNull($reloaded);
        /** @var array{type: string, coordinates: array<mixed>} $geo */
        $geo = json_decode((string) $reloaded->getGeom(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('MultiPolygon', $geo['type']);
        self::assertEqualsWithDelta(4.0, $geo['coordinates'][0][0][0][0], 0.000001);

        // The geometry is queryable spatially: a point inside the square is contained.
        $contains = (bool) $em->getConnection()->fetchOne(
            'SELECT ST_Contains(geom, ST_SetSRID(ST_Point(4.5, 50.5), 4326)) FROM region WHERE slug = :s',
            ['s' => 'test-square'],
        );
        self::assertTrue($contains);
    }
}
