<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The public API PoC surface (public-api.md §2.2): both /v1 endpoints, the
 * CORS wildcard on every outcome, the shared-cache discipline (public +
 * max-age + ETag, the header that silently degrades to private if the
 * ^/v1/ PUBLIC_ACCESS entry ever falls out of security.yaml), and the
 * personal-data boundary (feature properties are a closed list).
 */
final class PublicApiV1Test extends WebTestCase
{
    public function testMapConfigServesTheBootstrapCorsOpenAndCacheable(): void
    {
        $client = static::createClient();

        $client->request('GET', '/v1/map-config');
        self::assertResponseIsSuccessful();
        $response = $client->getResponse();

        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('max-age=3600', (string) $response->headers->get('Cache-Control'));
        self::assertNotEmpty($response->getEtag());

        /** @var array<string, mixed> $config */
        $config = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(12, $config['categories']);
        self::assertArrayHasKey('bestOf', $config['categories'][0]);
        self::assertSame('routes_{cc}', $config['routes']['sourceLayers']['lines']);
        self::assertArrayHasKey('tilesUrl', $config['routes']);   // null here: no manifest in test env
        self::assertCount(3, $config['routes']['style']['groups']);
        self::assertSame('{letter}_{cc}', $config['coverage']['sourceLayers']['points']);
        self::assertSame(['b', 'c', 'd', 'f', 'g', 'o', 'p', 'q'], $config['coverage']['letters']);
        self::assertContains('zz', $config['coverage']['countries']);
        self::assertSame(9, $config['coverage']['minZoom']);
        self::assertStringContainsString('Cycling Commons', (string) $config['attribution']);
        self::assertStringContainsString('OpenStreetMap', (string) $config['attribution']);

        // Conditional revalidation replays as 304, and the CORS header must
        // ride the 304 too, or a browser consumer's revalidation goes opaque.
        $client->request('GET', '/v1/map-config', [], [], ['HTTP_IF_NONE_MATCH' => $response->getEtag()]);
        self::assertResponseStatusCodeSame(304);
        self::assertSame('*', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));

        // Defensive preflight answer for clients that send one anyway.
        $client->request('OPTIONS', '/v1/map-config');
        self::assertResponseStatusCodeSame(204);
        self::assertSame('*', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    public function testSearchServesViewportGeoJsonInsidePublicBoundary(): void
    {
        $client = static::createClient();
        $this->seedCatalog($client);

        // Fixture D items sit at (4.4,50.7), (4.9,50.3) and (6.5,49.0); the
        // box catches the first two.
        $client->request('GET', '/v1/search?bbox=4.0,50.0,5.0,51.0&letter=D');
        self::assertResponseIsSuccessful();
        $response = $client->getResponse();

        self::assertSame('application/geo+json', $response->headers->get('Content-Type'));
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'));

        /** @var array<string, mixed> $collection */
        $collection = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('FeatureCollection', $collection['type']);
        self::assertSame('ODbL-1.0', $collection['licence']);
        self::assertStringContainsString('Cycling Commons', (string) $collection['attribution']);
        self::assertCount(2, $collection['features']);

        foreach ($collection['features'] as $feature) {
            self::assertSame('Feature', $feature['type']);
            self::assertSame('Point', $feature['geometry']['type']);
            // The personal-data boundary, as a closed property list: a new
            // SELECT column cannot reach the response without failing here.
            self::assertSame(['id', 'letter', 'name', 'tier'], array_keys($feature['properties']));
            self::assertSame('D', $feature['properties']['letter']);
            self::assertContains($feature['properties']['tier'], ['community', 'curated']);
        }

        // limit caps the collection.
        $client->request('GET', '/v1/search?bbox=4.0,50.0,5.0,51.0&letter=D&limit=1');
        /** @var array<string, mixed> $capped */
        $capped = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(1, $capped['features']);

        // A valid letter with no rows in the box is an empty collection, not an error.
        $client->request('GET', '/v1/search?bbox=4.0,50.0,5.0,51.0&letter=C');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $empty */
        $empty = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([], $empty['features']);

        // No letter = all letters in one response (the mode-slider round);
        // this box only holds the two D fixtures, so the counts match.
        $client->request('GET', '/v1/search?bbox=4.0,50.0,5.0,51.0');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $all */
        $all = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(2, $all['features']);

        // The tier filter: the seed promotes every fixture to verified, so
        // curated returns them all and community returns none.
        $client->request('GET', '/v1/search?bbox=4.0,50.0,5.0,51.0&tier=curated');
        /** @var array<string, mixed> $curated */
        $curated = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(2, $curated['features']);
        $client->request('GET', '/v1/search?bbox=4.0,50.0,5.0,51.0&tier=community');
        /** @var array<string, mixed> $community */
        $community = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([], $community['features']);
    }

    public function testSearchRejectsBadParametersWithCorsOnTheError(): void
    {
        $client = static::createClient();

        $cases = [
            ['/v1/search', 'invalid_bbox'],                                     // nothing at all (letter is optional now)
            ['/v1/search?letter=Z&bbox=4.0,50.0,5.0,51.0', 'invalid_letter'],   // not a catalogue letter
            ['/v1/search?letter=B', 'invalid_bbox'],                            // bbox missing
            ['/v1/search?letter=B&bbox=1,2,3', 'invalid_bbox'],                 // three numbers
            ['/v1/search?letter=B&bbox=4.0,50.0,x,51.0', 'invalid_bbox'],       // not numeric
            ['/v1/search?letter=B&bbox=5.0,50.0,4.0,51.0', 'invalid_bbox'],     // min >= max
            ['/v1/search?letter=B&bbox=190,50.0,195,51.0', 'invalid_bbox'],     // off the planet
            ['/v1/search?letter=B&bbox=0,0,60,60', 'bbox_too_large'],           // continent-sized
            ['/v1/search?bbox=4.0,50.0,5.0,51.0&tier=gold', 'invalid_tier'],    // unknown tier
        ];

        foreach ($cases as [$url, $error]) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(400, $url);
            self::assertSame('*', $client->getResponse()->headers->get('Access-Control-Allow-Origin'), $url);
            /** @var array{error: string, message: string} $body */
            $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            self::assertSame($error, $body['error'], $url);
            self::assertNotSame('', $body['message'], $url);
        }
    }

    /** The CatalogEndpointTest seeding recipe: import fixtures, promote to a human-touched served state. */
    private function seedCatalog(KernelBrowser $client): void
    {
        $src = __DIR__.'/../fixtures/catalog';
        $dir = sys_get_temp_dir().'/public-api-v1-'.getmypid();
        @mkdir($dir, 0777, true);
        foreach (['region-square.geojson', 'services.json', 'routes.json', 'heat.json'] as $f) {
            copy($src.'/'.$f, $dir.'/'.$f);
        }
        $tester = new CommandTester((new Application(self::$kernel))->find('app:catalog:import'));
        $tester->execute(['dir' => $dir]);
        $tester->assertCommandIsSuccessful();

        // Untouched osm/unverified rows are coverage-retired from serving
        // (coverage-provider.md §9), for /v1/search exactly as for
        // catalog.json, so promote the fixtures like CatalogEndpointTest does.
        static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->getConnection()->executeStatement(
            "UPDATE item SET state = 'verified' WHERE letter IN ('B','D','F','G','O','P','Q')",
        );
    }
}
