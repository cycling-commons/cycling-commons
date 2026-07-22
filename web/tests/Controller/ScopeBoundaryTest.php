<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Catalog\Entity\Region;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * 2026-07-22-coverage-scope-rendering-design.md §B: the scope dim mask needs a
 * single unioned boundary for a whole country (or an explicit region set), so
 * the map can grey everything outside a country scope, not just a named region.
 */
final class ScopeBoundaryTest extends WebTestCase
{
    private function seedTwo(EntityManagerInterface $em): void
    {
        // two adjacent NL squares; their union is one polygon
        $em->persist((new Region())->setSlug('sb-a')->setName('A')->setCountryCode('NL')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[4,52],[5,52],[5,53],[4,53],[4,52]]]]}'));
        $em->persist((new Region())->setSlug('sb-b')->setName('B')->setCountryCode('NL')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[5,52],[6,52],[6,53],[5,53],[5,52]]]]}'));
        $em->flush();
    }

    public function testCountryUnionReturnsOneFeature(): void
    {
        $client = static::createClient();
        $this->seedTwo(static::getContainer()->get(EntityManagerInterface::class));
        $client->request('GET', '/map/scope/boundary?cc=NL');
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('Feature', $body['type']);
        self::assertContains($body['geometry']['type'], ['Polygon', 'MultiPolygon']);
        self::assertNotEmpty($client->getResponse()->getEtag());
    }

    public function testEmptyScopeIsNoContent(): void
    {
        static::createClient()->request('GET', '/map/scope/boundary');
        self::assertSame(204, static::getClient()->getResponse()->getStatusCode());
    }
}
