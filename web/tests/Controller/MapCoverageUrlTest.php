<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Coverage\CoverageManifest;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * coverage-provider.md §4: the map shell injects the coverage family into
 * window.CC_TILES only when the flag is on AND the manifest resolved — an
 * empty object otherwise, so map.js renders no coverage control.
 */
final class MapCoverageUrlTest extends WebTestCase
{
    public function testMapOmitsCoverageTilesWhenTilesAreOff(): void
    {
        $client = static::createClient();
        // Construct the manifest service with enabled=false explicitly —
        // this test must not trust ambient env. COVERAGE_TILES=1 is the
        // committed default (web/.env) since the default-on flip, and
        // developers/docker/compose.yaml passes the real container env var
        // through, which beats .env.test's placeholder. A dev whose
        // .env.example values point at a real, published manifest would
        // otherwise trigger a genuine MinIO/bucket fetch here. Same
        // override pattern as testMapInjectsCoverageTilesWhenManifestResolves
        // below, just flag-off.
        static::getContainer()->set(CoverageManifest::class, new CoverageManifest(
            new MockHttpClient(),   // never called — tilesEnabled=false short-circuits before any HTTP request
            new ArrayAdapter(),
            new NullLogger(),
            false,
            'https://maps.test/coverage/manifest.json',
        ));

        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertSame(1, preg_match('/window\.CC_TILES = (\{.*?\});/', $html, $m), 'CC_TILES global must be injected');
        $tiles = json_decode($m[1], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([], (array) $tiles['coverage']);
    }

    public function testMapInjectsCoverageTilesWhenManifestResolves(): void
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
        self::assertSame(1, preg_match('/window\.CC_TILES = (\{.*?\});/', $html, $m), 'CC_TILES global must be injected');
        $tiles = json_decode($m[1], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('https://maps.test/coverage/20260716-0400.pmtiles', $tiles['coverage']['*']['tiles']['points']);
    }
}
