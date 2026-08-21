<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

use AsyncAws\S3\S3Client;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;

/**
 * Public per-shard objects and the private quarantine bucket.
 *
 * @see docs/specs/media-storage-architecture.md §2, §4
 *
 * @api
 */
final class MediaStorage
{
    public const array VARIANTS = ['orig', 'lg', 'sm'];

    /** Unscanned bytes live only in private storage. @see docs/specs/media-storage-architecture.md §2.2 */
    private const string QUARANTINE_PREFIX = 'quarantine/';

    /** @var array<string, FilesystemOperator> bucket name => filesystem, built on demand */
    private array $filesystems;

    /**
     * @param array<string, string>             $activeBuckets continent => full bucket name NEW photos write to
     * @param array<string, FilesystemOperator> $filesystems   bucket name => filesystem; the test seam (prod
     *                                                         builds S3 filesystems lazily from $client)
     */
    public function __construct(
        private readonly ?S3Client $client,
        private readonly array $activeBuckets,
        private readonly string $privateBucket,
        private readonly string $publicBase,
        array $filesystems = [],
    ) {
        $this->filesystems = $filesystems;
    }

    /**
     * Active bucket for a continent's new photos; stored on the row, never re-derived.
     *
     * @throws ShardUnavailable          when the continent's bucket var is unset
     * @throws \InvalidArgumentException when the configured name does not end in -<cc>-<nn>
     *
     * @see docs/specs/media-storage-architecture.md §2.1
     */
    public function bucketFor(string $continent): string
    {
        $bucket = $this->activeBuckets[strtoupper($continent)] ?? '';
        if ('' === $bucket) {
            throw new ShardUnavailable(strtoupper($continent));
        }
        self::assertSegmentable($bucket);

        return $bucket;
    }

    /** @param resource|string $bytes */
    public function writeQuarantine(string $mediaId, mixed $bytes): void
    {
        if (\is_string($bytes)) {
            $this->filesystemFor($this->privateBucket)->write(self::QUARANTINE_PREFIX.$mediaId, $bytes);
        } else {
            $this->filesystemFor($this->privateBucket)->writeStream(self::QUARANTINE_PREFIX.$mediaId, $bytes);
        }
    }

    /** True while the quarantine object still exists (release no-op guard). @see docs/specs/media-storage-architecture.md §3 */
    public function quarantineExists(string $mediaId): bool
    {
        try {
            return $this->filesystemFor($this->privateBucket)->fileExists(self::QUARANTINE_PREFIX.$mediaId);
        } catch (FilesystemException) {
            return false;
        }
    }

    /** Quarantined bytes, or null when gone. */
    public function readQuarantine(string $mediaId): ?string
    {
        try {
            return $this->filesystemFor($this->privateBucket)->read(self::QUARANTINE_PREFIX.$mediaId);
        } catch (FilesystemException) {
            return null;
        }
    }

    /** Idempotent: release and disposal both call it. */
    public function deleteQuarantine(string $mediaId): void
    {
        try {
            $this->filesystemFor($this->privateBucket)->delete(self::QUARANTINE_PREFIX.$mediaId);
        } catch (FilesystemException) {
            // Already gone.
        }
    }

    public function store(string $bucket, string $prefix, ProcessedPhoto $photo): void
    {
        $filesystem = $this->filesystemFor($bucket);
        $filesystem->write($prefix.'/orig.webp', $photo->orig);
        $filesystem->write($prefix.'/lg.webp', $photo->lg);
        $filesystem->write($prefix.'/sm.webp', $photo->sm);
    }

    /**
     * Copy variants inside one shard. Copy-then-row-then-delete for the key backfill.
     *
     * @see docs/specs/media-storage-architecture.md §4.1
     */
    public function copyVariants(string $bucket, string $fromPrefix, string $toPrefix): int
    {
        $filesystem = $this->filesystemFor($bucket);
        $copied = 0;
        foreach (self::VARIANTS as $variant) {
            try {
                if (!$filesystem->fileExists($fromPrefix.'/'.$variant.'.webp')) {
                    continue;
                }
                $filesystem->copy($fromPrefix.'/'.$variant.'.webp', $toPrefix.'/'.$variant.'.webp');
                ++$copied;
            } catch (FilesystemException) {
                // Report what actually landed; the caller decides.
            }
        }

        return $copied;
    }

    /**
     * How many of the three variants exist under the prefix. Zero means the
     * bucket does not hold this upload.
     *
     * @see docs/specs/media-storage-architecture.md §4
     */
    public function variantsExist(string $bucket, string $prefix): int
    {
        $filesystem = $this->filesystemFor($bucket);
        $found = 0;
        foreach (self::VARIANTS as $variant) {
            try {
                if ($filesystem->fileExists($prefix.'/'.$variant.'.webp')) {
                    ++$found;
                }
            } catch (FilesystemException) {
                // Unreachable shard counts as absent; the caller decides.
            }
        }

        return $found;
    }

    /** Delete every object under the prefix. Idempotent. */
    public function deletePrefix(string $bucket, string $prefix): void
    {
        try {
            $this->filesystemFor($bucket)->deleteDirectory($prefix);
        } catch (FilesystemException) {
            // Already absent, or the shard is unreachable.
        }
    }

    /**
     * Open one stored variant, or null if missing.
     *
     * @return resource|null
     *
     * @api
     */
    public function readStream(string $bucket, string $prefix, string $variant)
    {
        self::assertVariant($variant);

        try {
            return $this->filesystemFor($bucket)->readStream($prefix.'/'.$variant.'.webp');
        } catch (FilesystemException) {
            return null;
        }
    }

    public function url(string $bucket, string $prefix, string $variant): string
    {
        self::assertVariant($variant);
        self::assertSegmentable($bucket);

        // URL segment is the bucket name's last five characters; the name itself never appears. @see docs/specs/media-storage-architecture.md §2.0
        return rtrim($this->publicBase, '/').'/'.substr($bucket, -5).'/'.$prefix.'/'.$variant.'.webp';
    }

    /** Public bucket names must end in -<cc>-<nn>. @see docs/specs/media-storage-architecture.md §2.1 */
    private static function assertSegmentable(string $bucket): void
    {
        if (1 !== preg_match('/-[a-z]{2}-\d{2}$/D', $bucket)) {
            throw new \InvalidArgumentException(\sprintf('Public media bucket "%s" must end in -<cc>-<nn> (e.g. -eu-01); its last five characters are the public URL segment.', $bucket));
        }
    }

    private static function assertVariant(string $variant): void
    {
        if (!\in_array($variant, self::VARIANTS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown photo variant "%s".', $variant));
        }
    }

    private function filesystemFor(string $bucket): FilesystemOperator
    {
        if ('' === $bucket) {
            throw new ShardUnavailable('(no bucket recorded)');
        }
        if (isset($this->filesystems[$bucket])) {
            return $this->filesystems[$bucket];
        }
        if (null === $this->client) {
            throw new ShardUnavailable($bucket);
        }

        return $this->filesystems[$bucket] = new Filesystem(new AsyncAwsS3Adapter($this->client, $bucket));
    }
}
