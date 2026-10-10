<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\BulkExport;

use App\BulkExport\BulkExportCatalog;
use App\BulkExport\BulkExportStorage;
use App\Tests\Translation\RecordingLogger;
use AsyncAws\S3\S3Client;
use League\Flysystem\Filesystem;
use League\Flysystem\UnableToReadFile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

/**
 * What the web frontends read about the published snapshots
 * (docs/specs/api-strategy.md §3.1): the newest manifest, from the shared
 * cache, and quietly nothing when storage is unset or down.
 */
final class BulkExportCatalogTest extends TestCase
{
    private SpyStorageAdapter $spy;

    private BulkExportStorage $storage;

    private ArrayAdapter $cache;

    private MockClock $clock;

    private RecordingLogger $logger;

    private BulkExportCatalog $catalog;

    #[\Override]
    protected function setUp(): void
    {
        $this->spy = new SpyStorageAdapter();
        $this->storage = new BulkExportStorage(null, 'test-data-export', new Filesystem($this->spy));
        // Starts at the real time: a cache item computes its expiry from the
        // real clock, the adapter compares it with this one.
        $this->clock = new MockClock();
        $this->cache = new ArrayAdapter(clock: $this->clock);
        $this->logger = new RecordingLogger();
        $this->catalog = new BulkExportCatalog($this->storage, $this->cache, $this->logger);
    }

    public function testTheNewestManifestIsReadOnceFromTheExportFolder(): void
    {
        $this->publish('20261002T050700Z', latest: true);
        $this->publish('20261009T050700Z');
        $this->spy->calls = [];

        self::assertSame('20261002T050700Z', $this->catalog->latest()['snapshot'] ?? null);
        self::assertSame(['fileExists exports/latest.json', 'read exports/latest.json'], $this->spy->calls);

        // Cached: the next page view asks no storage.
        self::assertSame('20261002T050700Z', $this->catalog->latest()['snapshot'] ?? null);
        self::assertCount(2, $this->spy->calls);
    }

    public function testANewSnapshotIsSeenOnceTheBuilderForgetsTheCache(): void
    {
        $this->publish('20261002T050700Z', latest: true);
        self::assertSame('20261002T050700Z', $this->catalog->latest()['snapshot'] ?? null);

        $this->publish('20261009T050700Z', latest: true);
        self::assertSame('20261002T050700Z', $this->catalog->latest()['snapshot'] ?? null, 'cached until the builder says otherwise');
        $this->catalog->forget();

        self::assertSame('20261009T050700Z', $this->catalog->latest()['snapshot'] ?? null);
    }

    public function testStorageThatFailsIsAskedAgainSoonAndWarnedAboutOncePerWindow(): void
    {
        $this->publish('20261009T050700Z', latest: true);
        $this->spy->failWhen = static function (string $operation, string $path): void {
            throw UnableToReadFile::fromLocation($path, 'storage is down');
        };

        self::assertNull($this->catalog->latest());
        self::assertSame(['warning'], $this->levels(), 'a storage failure is one warning, never an error');

        // Remembered for a short while: no storage call on the next page view.
        $calls = \count($this->spy->calls);
        self::assertNull($this->catalog->latest());
        self::assertSame($calls, \count($this->spy->calls));

        // Asked again after the retry delay, still failing: no second warning in the window.
        $this->clock->sleep(61);
        self::assertNull($this->catalog->latest());
        self::assertGreaterThan($calls, \count($this->spy->calls));
        self::assertSame(['warning'], $this->levels());

        // A new window, a new warning.
        $this->clock->sleep(3600);
        self::assertNull($this->catalog->latest());
        self::assertSame(['warning', 'warning'], $this->levels());

        // Storage back: the next attempt finds the snapshot.
        $this->spy->failWhen = null;
        $this->clock->sleep(61);
        self::assertSame('20261009T050700Z', $this->catalog->latest()['snapshot'] ?? null);
    }

    public function testAPlaceholderBucketIsNotConfigured(): void
    {
        $client = new S3Client(['region' => 'eu-central', 'endpoint' => 'http://127.0.0.1:9', 'accessKeyId' => 'k', 'accessKeySecret' => 's']);

        foreach (['', '  ', 'replace-me', ' replace-me '] as $bucket) {
            $storage = new BulkExportStorage($client, $bucket);
            self::assertFalse($storage->isConfigured(), "'{$bucket}' names no bucket");

            $catalog = new BulkExportCatalog($storage, $this->cache, $this->logger);
            self::assertNull($catalog->latest());
        }
        self::assertSame([], $this->logger->records, 'an unset bucket is not an error');
        self::assertSame([], $this->cache->getValues());

        self::assertTrue((new BulkExportStorage($client, 'cc-maps'))->isConfigured());
    }

    private function publish(string $stamp, bool $latest = false): void
    {
        $json = json_encode(['format' => 1, 'snapshot' => $stamp, 'generated_at' => '2026-10-09T05:07:00Z', 'files' => []], \JSON_THROW_ON_ERROR);
        $this->storage->write($stamp.'/places.geojson.gz', 'gz');
        $this->storage->write($stamp.'/manifest.json', $json);
        if ($latest) {
            $this->storage->write(BulkExportStorage::LATEST, $json);
        }
    }

    /** @return list<string> */
    private function levels(): array
    {
        return array_map(static fn (array $r): string => $r['level'], $this->logger->records);
    }
}
