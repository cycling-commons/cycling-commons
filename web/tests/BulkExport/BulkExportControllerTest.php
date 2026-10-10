<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\BulkExport;

use App\BulkExport\BulkExportBuilder;
use App\BulkExport\BulkExportStorage;
use League\Flysystem\Filesystem;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/**
 * The export's page and its `latest` links (docs/specs/api-strategy.md §3.1):
 * the page lists the newest snapshot and links its files, and the `latest`
 * links redirect to them. The files themselves are nginx's to serve, straight
 * from the bucket: the app has no route for them.
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
            self::assertSelectorExists('[data-export-page] a', 'the export page is linked before the first snapshot too');
            self::assertStringNotContainsString('/data/export/', (string) $client->getResponse()->getContent(), $path);
        }
    }

    public function testTheDeveloperPageLinksTheExportOncePublished(): void
    {
        $client = $this->client();
        $this->build('2026-10-09 13:39:00 UTC');

        $client->request('GET', '/developers');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-export-page] a[href="/developers/export"]');
        // The downloads are listed on the export page, not as a row among the API endpoints.
        self::assertStringNotContainsString('/data/export/', (string) $client->getResponse()->getContent());
    }

    public function testTheBuildTimeNamesItsWeek(): void
    {
        $client = $this->client();
        $this->build('2026-10-09 13:39:00 UTC');

        $client->request('GET', '/developers/export');

        // 2026-10-09 falls in ISO week 41.
        self::assertSelectorTextContains('[data-export-built]', 'week 41');
        // In the reader's own date format (cc_datetime): a visitor gets the language's default.
        self::assertSelectorTextContains('[data-export-built]', 'Oct 9, 2026');
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

    public function testTheLatestLinksRedirectToTheNewestSnapshot(): void
    {
        $client = $this->client();
        $manifest = $this->build('2026-10-09 13:39:00 UTC');

        foreach (['places.geojson.gz', 'routes.geojson.gz', 'manifest.json'] as $file) {
            foreach (['GET', 'HEAD'] as $method) {
                $client->request($method, '/data/export/latest/'.$file);
                self::assertResponseRedirects('/data/export/'.$manifest['snapshot'].'/'.$file, 302, "{$method} {$file}");
                $cache = (string) $client->getResponse()->headers->get('Cache-Control');
                self::assertStringContainsString('public', $cache);
                self::assertStringContainsString('max-age=300', $cache);
            }
        }

        $client->request('GET', '/data/export/latest/users.csv');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheAppHasNoRouteForASnapshotsFiles(): void
    {
        $client = $this->client();
        $spy = new SpyStorageAdapter();
        static::getContainer()->set(BulkExportStorage::class, new BulkExportStorage(null, 'test-data-export', new Filesystem($spy)));
        $manifest = $this->build('2026-10-09 13:39:00 UTC');
        $router = static::getContainer()->get(RouterInterface::class);

        foreach (['places.geojson.gz', 'routes.geojson.gz', 'manifest.json'] as $file) {
            $path = '/data/export/'.$manifest['snapshot'].'/'.$file;
            foreach (['GET', 'HEAD'] as $method) {
                $router->setContext((new RequestContext())->setMethod($method));
                try {
                    $match = $router->match($path);
                    self::fail("{$method} {$path} is nginx's to serve, but matches route ".(string) ($match['_route'] ?? '?'));
                } catch (ResourceNotFoundException) {
                }

                // Should a request reach the app anyway, it is a plain 404 that reads no storage.
                $calls = \count($spy->calls);
                $client->request($method, $path);
                self::assertResponseStatusCodeSame(404, "{$method} {$path}");
                self::assertSame([], \array_slice($spy->calls, $calls), "{$method} {$path} reads no storage");
            }
        }
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
}
