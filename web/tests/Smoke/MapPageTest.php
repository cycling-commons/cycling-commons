<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MapPageTest extends WebTestCase
{
    public function testMapRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav.rail-nav');   // map's own chrome
        self::assertSelectorExists('div#map');         // MapLibre mount point
    }

    public function testMapLoadsExtractedAppScript(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        // map.js no longer ships as a direct script tag — it arrives via the catalog loader
        self::assertGreaterThan(0, $crawler->filter('script[src*="map/catalog-load"]')->count());
    }

    public function testMapBootsFromCatalogEndpoint(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('window.CC_CATALOG_URL', $html);
        self::assertStringContainsString('/map/catalog.json', $html);
        self::assertStringContainsString('window.CC_MAP_SRC', $html);
        self::assertStringContainsString('map/catalog-load', $html);
        self::assertStringNotContainsString('src="/assets/data/', $html);
        self::assertStringNotContainsString('stays-merge', $html);
        self::assertSame(0, preg_match('#<script src="[^"]*\bmap/map\b[^"]*"#', $html), 'map.js must arrive via the loader, not a direct script tag');

        $tokenPos = strpos($html, 'window.MAPILLARY_TOKEN =');
        self::assertNotFalse($tokenPos, 'Mapillary token global must be injected into the map shell');
        $loaderPos = strpos($html, 'map/catalog-load');
        self::assertNotFalse($loaderPos, 'catalog loader script must be present');
        self::assertLessThan($loaderPos, $tokenPos, 'window.MAPILLARY_TOKEN must be defined before the catalog loader');
    }
}
