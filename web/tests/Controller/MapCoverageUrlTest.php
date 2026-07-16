<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Coverage\CoverageManifest;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * coverage-provider.md §4: the map shell injects
 * window.CC_COVERAGE_URL only when the flag is on AND the manifest resolved —
 * absent otherwise, so map.js keys the whole coverage source on its presence.
 */
final class MapCoverageUrlTest extends WebTestCase
{
    public function testMapOmitsCoverageUrlWhenTilesAreOff(): void
    {
        // COVERAGE_TILES=0 is the committed default (web/.env) — no override needed.
        $client = static::createClient();
        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('window.CC_COVERAGE_URL', (string) $client->getResponse()->getContent());
    }

    public function testMapInjectsCoverageUrlWhenManifestResolves(): void
    {
        $client = static::createClient();
        // Replace the (not-yet-instantiated) service with a flag-on instance
        // backed by a mocked bucket — no network in tests.
        static::getContainer()->set(CoverageManifest::class, new CoverageManifest(
            new MockHttpClient(new JsonMockResponse([
                'version' => 1,
                'url' => 'https://maps.test/coverage/20260716-0400.pmtiles',
                'built_at' => '2026-07-16T04:00:00+00:00',
            ])),
            new ArrayAdapter(),
            new NullLogger(),
            true,
            'https://maps.test/coverage/manifest.json',
        ));

        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertSame(1, preg_match('/window\.CC_COVERAGE_URL = ("[^"]+");/', $html, $m), 'CC_COVERAGE_URL global must be injected');
        self::assertSame('https://maps.test/coverage/20260716-0400.pmtiles', json_decode($m[1], flags: \JSON_THROW_ON_ERROR));
    }
}
