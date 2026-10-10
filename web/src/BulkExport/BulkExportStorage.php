<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\BulkExport;

use AsyncAws\S3\S3Client;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;

/**
 * The folder the bulk export lives in (docs/specs/api-strategy.md §3.1).
 *
 * Object storage, because the worker host writes a snapshot and nginx on
 * either web frontend serves it. The export has no bucket of its own: it
 * keeps everything under `exports/` in an existing bucket (the map tile
 * bucket in production), named by `DATA_EXPORT_BUCKET` and reached with the
 * media S3 client. Nothing outside that folder is ever listed, written or
 * deleted here. Layout:
 *
 *     exports/latest.json                          the newest snapshot's manifest, written last
 *     exports/snapshots/<stamp>/manifest.json
 *     exports/snapshots/<stamp>/places.geojson.gz
 *     exports/snapshots/<stamp>/routes.geojson.gz
 *
 * Paths given to this class are relative to `exports/snapshots/`, except
 * `latest.json`.
 *
 * Every object is written with the headers nginx passes through as they are
 * ({@see metadata()}): its type, its cache policy, and for a data file the
 * name a browser saves it under.
 *
 * A snapshot is published once its `manifest.json` is there: the builder
 * writes the manifest after the files, and deletes the directory again when a
 * build fails halfway ({@see published()}).
 *
 * @api
 */
final class BulkExportStorage
{
    public const string LATEST = 'latest.json';

    /** The export's folder in the bucket: every key it touches starts with it. */
    public const string FOLDER = 'exports/';

    private const string SNAPSHOTS = self::FOLDER.'snapshots/';

    /** A snapshot's files never change once published; a week is the build cadence. */
    private const string IMMUTABLE = 'public, max-age=604800, immutable';

    /** A snapshot's stamp: its UTC build time, to the second. */
    public const string STAMP_PATTERN = '\d{8}T\d{6}Z';

    /**
     * The value the committed staging env file carries for every deployment
     * setting until the host's local override supplies the real one. A host
     * that never did has no bucket, the same as an empty value.
     */
    public const string UNSET_PLACEHOLDER = 'replace-me';

    private const string MANIFEST = 'manifest.json';

    private ?FilesystemOperator $filesystem;

    /**
     * @param FilesystemOperator|null $filesystem the test seam; production builds an S3 filesystem from $client and $bucket
     */
    public function __construct(
        private readonly ?S3Client $client,
        private readonly string $bucket,
        ?FilesystemOperator $filesystem = null,
    ) {
        $this->filesystem = $filesystem;
    }

    /**
     * False while no bucket is configured (empty, or still the placeholder):
     * the pages then say nothing is published, and the command refuses.
     */
    public function isConfigured(): bool
    {
        if (null !== $this->filesystem) {
            return true;
        }
        $bucket = trim($this->bucket);

        return null !== $this->client && '' !== $bucket && self::UNSET_PLACEHOLDER !== $bucket;
    }

    /** @param resource $stream */
    public function writeStream(string $path, $stream): void
    {
        $this->fs()->writeStream($this->key($path), $stream, self::metadata($path));
    }

    public function write(string $path, string $contents): void
    {
        $this->fs()->write($this->key($path), $contents, self::metadata($path));
    }

    public function has(string $path): bool
    {
        return $this->fs()->fileExists($this->key($path));
    }

    public function read(string $path): string
    {
        return $this->fs()->read($this->key($path));
    }

    /** @return resource */
    public function readStream(string $path)
    {
        return $this->fs()->readStream($this->key($path));
    }

    /**
     * The stamps of every snapshot in storage, oldest first.
     *
     * @return list<string>
     */
    public function snapshots(): array
    {
        $stamps = [];
        foreach ($this->fs()->listContents(rtrim(self::SNAPSHOTS, '/'), false) as $entry) {
            $name = basename($entry->path());
            if ($entry->isDir() && 1 === preg_match('/^'.self::STAMP_PATTERN.'$/D', $name)) {
                $stamps[] = $name;
            }
        }
        sort($stamps, \SORT_STRING);

        return $stamps;
    }

    /**
     * The stamps of the snapshots that hold a manifest, oldest first: one
     * listing of the whole prefix, however many snapshots there are.
     *
     * @return list<string>
     */
    public function published(): array
    {
        $stamps = [];
        foreach ($this->fs()->listContents(rtrim(self::SNAPSHOTS, '/'), true) as $entry) {
            if ($entry->isFile() && 1 === preg_match('#^'.preg_quote(self::SNAPSHOTS, '#').'('.self::STAMP_PATTERN.')/'.preg_quote(self::MANIFEST, '#').'$#D', $entry->path(), $m)) {
                $stamps[] = $m[1];
            }
        }
        sort($stamps, \SORT_STRING);

        return $stamps;
    }

    /**
     * @throws \InvalidArgumentException when $stamp is no stamp: nothing else is ever deleted
     * @throws BulkExportUnavailable     when no bucket is configured
     * @throws FilesystemException       when storage refuses
     */
    public function deleteSnapshot(string $stamp): void
    {
        if (1 !== preg_match('/^'.self::STAMP_PATTERN.'$/D', $stamp)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a snapshot stamp.', $stamp));
        }
        $this->fs()->deleteDirectory(self::SNAPSHOTS.$stamp);
    }

    /** The object key: always inside the export's folder, whatever the path. */
    private function key(string $path): string
    {
        if (self::LATEST === $path) {
            return self::FOLDER.$path;
        }
        if (1 !== preg_match('#^'.self::STAMP_PATTERN.'(/[A-Za-z0-9][A-Za-z0-9._-]*)?$#D', $path)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a path inside a snapshot.', $path));
        }

        return self::SNAPSHOTS.$path;
    }

    /**
     * The object metadata S3 stores with the object and sends back as
     * headers. The data files are gzip files to save, not JSON sent
     * compressed, so they carry no Content-Encoding.
     *
     * @return array<string, string>
     */
    private static function metadata(string $path): array
    {
        if (self::LATEST === $path) {
            return ['ContentType' => 'application/json', 'CacheControl' => 'no-cache'];
        }
        if (1 === preg_match('#^('.self::STAMP_PATTERN.')/(places|routes)\.geojson\.gz$#D', $path, $m)) {
            return [
                'ContentType' => 'application/gzip',
                'CacheControl' => self::IMMUTABLE,
                'ContentDisposition' => sprintf('attachment; filename="cycling-commons-%s-%s.geojson.gz"', $m[2], $m[1]),
            ];
        }
        if (str_ends_with($path, '.json')) {
            return ['ContentType' => 'application/json', 'CacheControl' => self::IMMUTABLE];
        }

        return ['CacheControl' => self::IMMUTABLE];
    }

    private function fs(): FilesystemOperator
    {
        if (null !== $this->filesystem) {
            return $this->filesystem;
        }
        if (!$this->isConfigured() || null === $this->client) {
            throw new BulkExportUnavailable();
        }

        return $this->filesystem = new Filesystem(new AsyncAwsS3Adapter($this->client, $this->bucket));
    }
}
