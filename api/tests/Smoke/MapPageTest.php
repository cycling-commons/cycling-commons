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
        // the application script is the extracted asset, not inline
        self::assertGreaterThan(0, $crawler->filter('script[src*="map/map"]')->count());
    }
}
