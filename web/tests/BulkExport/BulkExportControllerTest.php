<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\BulkExport;

use App\BulkExport\BulkExportBuilder;
use App\BulkExport\BulkExportStorage;
use League\Flysystem\Filesystem;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\HttpKernel\Kernel;

/**
 * The export's page and its downloads (docs/specs/api-strategy.md §3.1): the
 * page lists the newest snapshot, and every file streams from storage with the
 * headers a mirror needs to fetch it once and check it.
 */
final class BulkExportControllerTest extends WebTestCase
{
    public function testThePageSaysSoWhenNothingHasBeenPublished(): void
    {
        $client = $this->client();

        $client->request('GET', '/developers/export');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-export-empty]');
        self::assertSelectorNotExists('[data-export-file]');

        // A clean 404: the export page saying the first snapshot is on its way.
        $client->request('GET', '/data/export/latest/places.geojson.gz');
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorExists('[data-export-empty]');

        // The developer page offers no link that would end in that 404.
        foreach (['/developers', '/fr/developpeurs', '/nl/ontwikkelaars', '/de/entwickler', '/es/desarrolladores'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
            self::assertSelectorNotExists('[data-export-link]');
            self::assertSelectorExists('[data-export-pending]');
            self::assertStringNotContainsString('/data/export/', (string) $client->getResponse()->getContent(), $path);
        }
    }

    public function testTheDeveloperPageLinksTheExportOncePublished(): void
    {
        $client = $this->client();
        $this->build('2026-10-09 13:39:00 UTC');

        $client->request('GET', '/developers');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-export-link] a[href="/data/export/latest/places.geojson.gz"]');
        self::assertSelectorNotExists('[data-export-pending]');
    }

    public function testThePageListsTheNewestSnapshotInEveryLanguage(): void
    {
        $client = $this->client();
        $manifest = $this->build('2026-10-09 13:39:00 UTC');

        foreach (['/developers/export', '/fr/developpeurs/export', '/nl/ontwikkelaars/export', '/de/entwickler/export', '/es/desarrolladores/exportacion'] as $path) {
            $crawler = $client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
            self::assertCount(2, $crawler->filter('[data-export-file]'), $path);
            self::assertSelectorExists('a[href="/data/export/'.$manifest['snapshot'].'/places.geojson.gz"]');
            self::assertSelectorExists('a[href="/data/export/'.$manifest['snapshot'].'/manifest.json"]');
            self::assertStringContainsString($manifest['files'][0]['sha256'], (string) $client->getResponse()->getContent());
        }
    }

    public function testADownloadStreamsFromStorageWithTheHeadersAMirrorNeeds(): void
    {
        $client = $this->client();
        $manifest = $this->build('2026-10-09 13:39:00 UTC');
        $file = $manifest['files'][0];
        $url = '/data/export/'.$manifest['snapshot'].'/'.$file['name'];

        $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertSame('application/gzip', $response->headers->get('Content-Type'));
        self::assertSame((string) $file['bytes'], $response->headers->get('Content-Length'));
        self::assertSame('"'.$file['sha256'].'"', $response->headers->get('ETag'));
        self::assertSame('Fri, 09 Oct 2026 13:39:00 GMT', $response->headers->get('Last-Modified'));
        $cache = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cache);
        self::assertStringContainsString('immutable', $cache);
        // A week, the snapshot cadence: a takedown leaves the shared cache within it.
        self::assertStringContainsString('max-age=604800', $cache);
        self::assertStringContainsString('s-maxage=604800', $cache);
        self::assertStringNotContainsString('31536000', $cache);
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('cycling-commons-places-'.$manifest['snapshot'].'.geojson.gz', (string) $response->headers->get('Content-Disposition'));
        self::assertNull($response->headers->get('Content-Encoding'), 'the gzip file is the payload, not a transfer encoding');
        $body = (string) $client->getInternalResponse()->getContent();
        self::assertSame($this->storage()->read($manifest['snapshot'].'/'.$file['name']), $body);
        self::assertSame($file['sha256'], hash('sha256', $body));

        // A mirror that already holds this file is told so, without the bytes.
        $client->request('GET', $url, server: ['HTTP_IF_NONE_MATCH' => '"'.$file['sha256'].'"']);
        self::assertResponseStatusCodeSame(304);
        self::assertSame('', (string) $client->getInternalResponse()->getContent());

        $client->request('HEAD', $url);
        self::assertResponseIsSuccessful();
        self::assertSame((string) $file['bytes'], $client->getResponse()->headers->get('Content-Length'));
        self::assertSame('', (string) $client->getInternalResponse()->getContent());
    }

    public function testTheManifestTheLatestLinkAndUnknownFiles(): void
    {
        $client = $this->client();
        $manifest = $this->build('2026-10-09 13:39:00 UTC');

        $client->request('GET', '/data/export/'.$manifest['snapshot'].'/manifest.json');
        self::assertResponseIsSuccessful();
        self::assertSame('application/json', $client->getResponse()->headers->get('Content-Type'));
        $served = json_decode((string) $client->getInternalResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame($manifest, $served);

        $client->request('GET', '/data/export/latest/routes.geojson.gz');
        self::assertResponseRedirects('/data/export/'.$manifest['snapshot'].'/routes.geojson.gz', 302);

        $client->request('GET', '/data/export/latest/manifest.json');
        self::assertResponseRedirects('/data/export/'.$manifest['snapshot'].'/manifest.json', 302);

        $client->request('GET', '/data/export/20200101T000000Z/places.geojson.gz');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/data/export/'.$manifest['snapshot'].'/users.csv');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/data/export/../latest.json');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAStampNobodyPublishedCostsNoStorageCallAndNoCacheKey(): void
    {
        $client = $this->client();
        $spy = new SpyStorageAdapter();
        static::getContainer()->set(BulkExportStorage::class, new BulkExportStorage(null, 'test-data-export', new Filesystem($spy)));
        $manifest = $this->build('2026-10-09 13:39:00 UTC');
        // A visitor has read the page, so the known snapshots are cached.
        $this->asProduction($client, 'GET', '/developers/export');
        $this->asProduction($client, 'GET', '/data/export/'.$manifest['snapshot'].'/manifest.json');
        self::assertResponseIsSuccessful();
        $calls = \count($spy->calls);
        $keys = $this->cacheKeys();

        for ($i = 0; $i < 50; ++$i) {
            $stamp = sprintf('%04d%02d%02dT%02d%02d%02dZ', random_int(2000, 2099), random_int(1, 12), random_int(1, 28), random_int(0, 23), random_int(0, 59), random_int(0, 59));
            if ($stamp === $manifest['snapshot']) {
                continue;
            }
            foreach (['GET', 'HEAD'] as $method) {
                $this->asProduction($client, $method, '/data/export/'.$stamp.'/'.['places.geojson.gz', 'routes.geojson.gz', 'manifest.json'][$i % 3]);
                self::assertResponseStatusCodeSame(404, $stamp);
            }
        }

        self::assertSame([], \array_slice($spy->calls, $calls), 'an unknown stamp is answered without asking storage');
        self::assertSame($keys, $this->cacheKeys(), 'an unknown stamp writes no cache key, not even a limiter count');
    }

    public function testTheLimiterRefusesAnAddressThatKeepsAsking(): void
    {
        $client = $this->client();
        $manifest = $this->build('2026-10-09 13:39:00 UTC');
        $file = $manifest['files'][0];
        $base = '/data/export/'.$manifest['snapshot'].'/';

        // Every request for a snapshot counts: the files, the manifest, a HEAD and a 304.
        $this->asProduction($client, 'GET', $base.$file['name']);
        self::assertResponseIsSuccessful();
        $this->asProduction($client, 'HEAD', $base.$file['name']);
        self::assertResponseIsSuccessful();
        $this->asProduction($client, 'GET', $base.$file['name'], ['HTTP_IF_NONE_MATCH' => '"'.$file['sha256'].'"']);
        self::assertResponseStatusCodeSame(304);
        for ($i = 3; $i < 30; ++$i) {
            $this->asProduction($client, 'GET', $base.'manifest.json');
            self::assertResponseIsSuccessful("request {$i}");
        }

        $this->asProduction($client, 'GET', $base.'manifest.json');

        self::assertResponseStatusCodeSame(429);
        $response = $client->getResponse();
        self::assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        self::assertDoesNotMatchRegularExpression('/\d/', (string) $response->getContent(), 'public copy never states the limit');

        $this->asProduction($client, 'GET', $base.$file['name']);
        self::assertResponseStatusCodeSame(429);
        $this->asProduction($client, 'HEAD', $base.$file['name']);
        self::assertResponseStatusCodeSame(429);

        // The latest link costs no storage and is not counted.
        $this->asProduction($client, 'GET', '/data/export/latest/places.geojson.gz');
        self::assertResponseRedirects($base.'places.geojson.gz', 302);
    }

    /** @return list<string> every key in the shared cache and in the download limiter's pool */
    private function cacheKeys(): array
    {
        $keys = [];
        foreach (['cache.app', 'cache.bulk_export_limiter'] as $id) {
            $pool = static::getContainer()->get($id);
            if ($pool instanceof TraceableAdapter) {
                $pool = $pool->getPool();
            }
            self::assertInstanceOf(ArrayAdapter::class, $pool, $id);
            foreach (array_keys($pool->getValues()) as $key) {
                $keys[] = $id.' '.$key;
            }
        }
        sort($keys);

        return $keys;
    }

    /**
     * One request with the shared cache kept as production keeps it. There
     * the cache is Redis and outlives the request; in the suite every pool is
     * an array that services_resetter empties before each request after the
     * first, which would hide exactly what these tests watch: the known list
     * and the limiter's count carried from one request to the next.
     *
     * @param array<string, string> $server
     */
    private function asProduction(KernelBrowser $client, string $method, string $uri, array $server = []): void
    {
        (new \ReflectionProperty(Kernel::class, 'resetServices'))->setValue($client->getKernel(), false);
        $client->request($method, $uri, server: $server);
    }

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        // The in-memory storage lives in this kernel's container: a reboot would empty it.
        $client->disableReboot();

        return $client;
    }

    /** @return array{snapshot: string, files: list<array{name: string, bytes: int, sha256: string}>} */
    private function build(string $at): array
    {
        /* @var array{snapshot: string, files: list<array{name: string, bytes: int, sha256: string}>} */
        return static::getContainer()->get(BulkExportBuilder::class)->build(new \DateTimeImmutable($at));
    }

    private function storage(): BulkExportStorage
    {
        return static::getContainer()->get(BulkExportStorage::class);
    }
}
