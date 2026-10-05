<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Catalog\Entity\Region;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * map-and-search.md §4.5: the injected region registry
 * (window.CC_REGIONS) must carry a localized `label` + `countryLabel` so the
 * client can search and render scope chips without re-fetching or re-translating.
 */
final class MapScopeRegistryTest extends WebTestCase
{
    public function testInjectedRegionsCarryLabels(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new Region())->setSlug('bayern')->setName('Bavaria')->setCountryCode('DE')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[10,48],[12,48],[12,50],[10,50],[10,48]]]]}'));
        $em->flush();

        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        $flat = preg_replace('/\s+/', '', $html) ?? '';
        self::assertMatchesRegularExpression('/"slug":"bayern"[^}]*"label":"Bavaria"/', $flat, 'a region with no labels reads its name');
        self::assertMatchesRegularExpression('/"slug":"bayern"[^}]*"countryLabel":"AllGermany"/', $flat, 'the country phrase comes from country.labels');
        self::assertMatchesRegularExpression('#window\.CC_TZ_COUNTRY=\{[^;]*"Europe\\\\?/Berlin":"DE"#', $flat, 'the timezone map is served');
    }
}
