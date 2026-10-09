<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\BulkExport;

use AsyncAws\S3\S3Client;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;

/**
 * The bucket the bulk export lives in (docs/specs/api-strategy.md §3.1).
 *
 * Object storage, because the worker host writes a snapshot and either of the
 * two web frontends may serve it. The bucket is named by `DATA_EXPORT_BUCKET`
 * and reached with the media S3 client (one endpoint, one set of credentials);
 * it needs no anonymous-read policy, since every byte is streamed through the
 * site. Layout:
 *
 *     latest.json                          the newest snapshot's manifest, written last
 *     snapshots/<stamp>/manifest.json
 *     snapshots/<stamp>/places.geojson.gz
 *     snapshots/<stamp>/routes.geojson.gz
 *
 * Paths given to this class are relative to `snapshots/`, except `latest.json`.
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

    private const string SNAPSHOTS = 'snapshots/';

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
        $this->fs()->writeStream($this->key($path), $stream);
    }

    public function write(string $path, string $contents): void
    {
        $this->fs()->write($this->key($path), $contents);
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

    public function deleteSnapshot(string $stamp): void
    {
        $this->fs()->deleteDirectory(self::SNAPSHOTS.$stamp);
    }

    private function key(string $path): string
    {
        return self::LATEST === $path ? $path : self::SNAPSHOTS.$path;
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
