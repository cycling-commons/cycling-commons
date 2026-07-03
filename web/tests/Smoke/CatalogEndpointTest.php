<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CatalogEndpointTest extends WebTestCase
{
    public function testCatalogJsonServesAllLettersWithCacheHeaders(): void
    {
        $client = static::createClient();

        // Seed via the importer on the same kernel (DAMA rolls it back).
        $src = __DIR__.'/../fixtures/catalog';
        $dir = sys_get_temp_dir().'/catalog-endpoint-'.getmypid();
        @mkdir($dir, 0777, true);
        foreach (['region-square.geojson', 'services.json', 'routes.json', 'heat.json'] as $f) {
            copy($src.'/'.$f, $dir.'/'.$f);
        }
        $tester = new CommandTester((new Application(self::$kernel))->find('app:catalog:import'));
        $tester->execute(['dir' => $dir]);
        $tester->assertCommandIsSuccessful();

        $client->request('GET', '/map/catalog.json');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $response = $client->getResponse();
        self::assertNotEmpty($response->getEtag());
        self::assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('max-age=3600', (string) $response->headers->get('Cache-Control'));

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        foreach (['A', 'B', 'C', 'D', 'E', 'G', 'H', 'I', 'J', 'K', 'L'] as $letter) {
            self::assertArrayHasKey($letter, $data);
        }
        self::assertCount(3, $data['D']['features']);
        self::assertCount(1, $data['K']);
        self::assertCount(2, $data['L']);

        // Conditional revalidation: replaying the ETag yields 304 with no body.
        $client->request('GET', '/map/catalog.json', [], [], ['HTTP_IF_NONE_MATCH' => $response->getEtag()]);
        self::assertResponseStatusCodeSame(304);
    }
}
