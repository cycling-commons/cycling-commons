<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Catalog\Entity\Region;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The world map on /regions reads every stored outline as GeoJSON
 * (owner 2026-09-08). One ring per polygon, closed, three decimals.
 */
final class RegionOutlinesTest extends WebTestCase
{
    public function testOutlinesAreServedAsClosedPolygonsPerCountry(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $region = (new Region())->setSlug('outline-test-'.bin2hex(random_bytes(3)))->setName('Outline test')->setCountryCode('XX')
            ->setGeom('{"type":"Polygon","coordinates":[[[5.0,50.0],[5.5,50.0],[5.5,50.5],[5.0,50.0]]]}');
        $em->persist($region);
        $em->flush();
        $em->getConnection()->executeStatement("UPDATE region SET outline = '[[5.0,50.0,5.5,50.0,5.5,50.5,5.0,50.5]]' WHERE id = :id", ['id' => $region->getId()]);

        $client->request('GET', '/regions/outlines.json');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('public', (string) $client->getResponse()->headers->get('Cache-Control'), 'on the public cache list');
        self::assertNotEmpty((string) $client->getResponse()->headers->get('ETag'));
        /** @var array{type: string, features: list<array{properties: array{slug: string, cc: string}, geometry: array{type: string, coordinates: list<list<list<float>>>}}>} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('FeatureCollection', $data['type']);
        $mine = array_values(array_filter($data['features'], static fn (array $f): bool => 'XX' === $f['properties']['cc']));
        self::assertCount(1, $mine);
        $ring = $mine[0]['geometry']['coordinates'][0];
        self::assertSame('Polygon', $mine[0]['geometry']['type']);
        self::assertSame([5.0, 50.0], $ring[0]);
        self::assertSame($ring[0], $ring[\count($ring) - 1], 'closed');
        self::assertCount(5, $ring, 'four corners and the closing point');
    }
}
