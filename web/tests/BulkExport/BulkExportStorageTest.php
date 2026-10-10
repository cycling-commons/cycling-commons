<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\BulkExport;

use App\BulkExport\BulkExportStorage;
use AsyncAws\S3\S3Client;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Where the export keeps its objects and what each one tells nginx
 * (docs/specs/api-strategy.md §3.1): a folder of its own inside a bucket it
 * shares, and object metadata that the S3 adapter really sends with the PUT,
 * so the proxy can pass the headers through untouched.
 */
final class BulkExportStorageTest extends TestCase
{
    public function testTheMetadataTravelsWithThePutIntoTheExportFolder(): void
    {
        /** @var list<array{method: string, url: string, headers: array<string, string>}> $requests */
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $headers = [];
            foreach ($options['headers'] ?? [] as $line) {
                [$name, $value] = explode(':', (string) $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
            $requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers];

            return new MockResponse('', ['http_code' => 200]);
        });
        $client = new S3Client([
            'region' => 'eu-central',
            'endpoint' => 'http://storage.test',
            'pathStyleEndpoint' => true,
            'accessKeyId' => 'k',
            'accessKeySecret' => 's',
        ], null, $http);
        $storage = new BulkExportStorage($client, 'tiles');

        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'gz');
        rewind($stream);
        $storage->writeStream('20261009T133900Z/places.geojson.gz', $stream);
        $storage->write('20261009T133900Z/manifest.json', '{}');
        $storage->write(BulkExportStorage::LATEST, '{}');

        self::assertCount(3, $requests);
        [$places, $manifest, $latest] = $requests;

        self::assertSame('PUT', $places['method']);
        self::assertSame('http://storage.test/tiles/exports/snapshots/20261009T133900Z/places.geojson.gz', $places['url']);
        self::assertSame('application/gzip', $places['headers']['content-type'] ?? null);
        self::assertSame('public, max-age=604800, immutable', $places['headers']['cache-control'] ?? null);
        self::assertSame('attachment; filename="cycling-commons-places-20261009T133900Z.geojson.gz"', $places['headers']['content-disposition'] ?? null);
        self::assertArrayNotHasKey('content-encoding', $places['headers'], 'the gzip file is the payload');

        self::assertSame('http://storage.test/tiles/exports/snapshots/20261009T133900Z/manifest.json', $manifest['url']);
        self::assertSame('application/json', $manifest['headers']['content-type'] ?? null);
        self::assertSame('public, max-age=604800, immutable', $manifest['headers']['cache-control'] ?? null);
        self::assertArrayNotHasKey('content-disposition', $manifest['headers']);

        self::assertSame('http://storage.test/tiles/exports/latest.json', $latest['url']);
        self::assertSame('application/json', $latest['headers']['content-type'] ?? null);
        self::assertSame('no-cache', $latest['headers']['cache-control'] ?? null);
    }

    public function testAPathCannotLeaveASnapshot(): void
    {
        $spy = new SpyStorageAdapter();
        $storage = new BulkExportStorage(null, 'tiles', new Filesystem($spy));

        foreach (['../latest.json', '20261009T133900Z/../../coverage/manifest.json', '/coverage/manifest.json', 'coverage/manifest.json', '20261009T133900Z/../x', '20261009T133900Z//x', ''] as $path) {
            foreach (['write', 'has', 'read'] as $operation) {
                try {
                    'write' === $operation ? $storage->write($path, 'x') : $storage->{$operation}($path);
                    self::fail("{$operation} '{$path}' leaves the export's folder");
                } catch (\InvalidArgumentException) {
                }
            }
        }
        self::assertSame([], $spy->calls, 'storage is never asked for a path outside a snapshot');
    }

    public function testASnapshotIsDeletedOnlyByItsStamp(): void
    {
        $spy = new SpyStorageAdapter();
        $storage = new BulkExportStorage(null, 'tiles', new Filesystem($spy));

        foreach (['', '/', '..', '../coverage', '20261009T133900Z/..', '2026-10-09', '20261009T133900Z/places.geojson.gz'] as $stamp) {
            try {
                $storage->deleteSnapshot($stamp);
                self::fail("'{$stamp}' is no stamp");
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame([], $spy->calls, 'nothing is deleted for a value that is no stamp');

        $storage->deleteSnapshot('20261009T133900Z');
        self::assertSame(['deleteDirectory exports/snapshots/20261009T133900Z'], $spy->calls);
    }
}
