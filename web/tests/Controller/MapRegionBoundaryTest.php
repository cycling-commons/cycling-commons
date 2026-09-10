<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Catalog\Entity\Region;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The region spotlight boundary endpoint (map-and-search.md §4.5
 * Phase 1) that retired the map's Nominatim fetch: a simplified DB polygon,
 * served public + cacheable.
 */
final class MapRegionBoundaryTest extends WebTestCase
{
    public function testServesSimplifiedPolygonPublicAndCacheable(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $slug = 'boundary-test-'.bin2hex(random_bytes(4));
        $region = (new Region())->setSlug($slug)->setName('Boundary Test')->setCountryCode('BE')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[4.0,50.0],[5.0,50.0],[5.0,51.0],[4.0,51.0],[4.0,50.0]]]]}');
        $em->persist($region);
        $em->flush();

        // No login — public like catalog.json.
        $client->request('GET', '/map/region/'.$slug.'/boundary');
        self::assertResponseIsSuccessful();
        self::assertResponseHasHeader('Cache-Control');
        // PUBLIC_ACCESS in security.yaml keeps this cacheable, not downgraded to
        // private by scheb's session read.
        self::assertStringContainsString('public', (string) $client->getResponse()->headers->get('Cache-Control'));

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Feature', $data['type']);
        self::assertSame($slug, $data['properties']['slug']);
        self::assertContains($data['geometry']['type'], ['Polygon', 'MultiPolygon']);
        self::assertNotEmpty($data['geometry']['coordinates']);

        $em->remove($region);
        $em->flush();
    }

    public function testUnknownRegionReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map/region/does-not-exist-xyz/boundary');
        self::assertResponseStatusCodeSame(404);
    }
}
