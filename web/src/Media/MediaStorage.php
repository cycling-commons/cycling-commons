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
 * Writes, deletes and addresses photo objects across the per-shard public
 * storages, plus the one private storage that holds the quarantine
 * (docs/specs/media-storage-architecture.md §2, §4).
 *
 * Two storages, two jobs:
 *
 * - **public, per shard** - published derivatives only, at the immutable key
 *   published/<uuid>/<rev>/orig.webp | lg.webp | sm.webp. A key never changes
 *   meaning, which is what lets the proxy in front of it cache for a year (§4).
 * - **private, one** - quarantine/<uuid>, the raw unscanned bytes. Written by
 *   the web tier, read and deleted by the worker, reachable by nobody else:
 *   the bucket carries no anonymous-read policy at all (§2.2).
 *
 * The row is fully self-contained (owner 2026-08-20): it stores the FULL
 * bucket name its objects live in, plus the shard tag (EU-01) whose
 * lowercase form is the public URL segment. Storage operations address the
 * recorded bucket by name, building a filesystem for it on demand, so a
 * retired bucket needs no config entry to stay readable forever. Which
 * bucket a continent's NEW photos go to is the env pair
 * MEDIA_S3_PUBLIC_BUCKET_<CC> + MEDIA_ACTIVE_SHARD_<CC>, bumped together
 * when a continent advances a generation. A continent whose pair is unset
 * REFUSES the upload (ShardUnavailable; owner 2026-08-18: "storage must
 * fail"), never borrows another bucket.
 *
 * The browser-facing URL is <MEDIA_PUBLIC_BASE>/<lowercase shard>/<key>;
 * the proxy maps that segment to the bucket, so no bucket name is ever
 * public (media-storage-architecture.md §2.0).
 *
 * These paths are guessing-infeasible, NOT unguessable, and they are not
 * access control. A UUIDv4 carries ~122 random bits so blind enumeration is
 * impractical, but the path is still only a secret in a URL, and the variant
 * names are fixed, so anyone holding one variant's URL can derive its siblings
 * including the full-resolution original. Both are accepted for v1: every
 * variant is the same CC BY-SA work at a different size, and "public" before
 * approval means UNLINKED — the moderation queue is the only place a pending
 * URL appears, buckets are never listable, and the proxy must not serve
 * directory indexes. Real access control for pending media would mean serving
 * those objects through an authorizing layer; that option was considered
 * during design and deliberately not chosen (docs/specs/photo-uploads.md §2).
 *
 * @api Called by MediaController, ScanAndReleaseUploadHandler,
 *      MediaDisposalService and MediaDecisionService.
 */
final class MediaStorage
{
    public const array VARIANTS = ['orig', 'lg', 'sm'];

    /** Where an unscanned upload waits, in the private storage and nowhere else. */
    private const string QUARANTINE_PREFIX = 'quarantine/';

    /** @var array<string, FilesystemOperator> bucket name => filesystem, built on demand */
    private array $filesystems;

    /**
     * @param array<string, string>             $activeShards  continent => shard tag (EU-01) NEW photos record
     * @param array<string, string>             $activeBuckets continent => full bucket name NEW photos write to
     * @param array<string, FilesystemOperator> $filesystems   bucket name => filesystem; the test seam (prod
     *                                                         builds S3 filesystems lazily from $client)
     */
    public function __construct(
        private readonly ?S3Client $client,
        private readonly array $activeShards,
        private readonly array $activeBuckets,
        private readonly string $privateBucket,
        private readonly string $publicBase,
        array $filesystems = [],
    ) {
        $this->filesystems = $filesystems;
    }

    /**
     * Where a continent's NEW photos go right now: the shard tag and the full
     * bucket name, as one pair.
     *
     * Called once, at intake, and BOTH answers are STORED on the row. Never
     * re-derived afterwards: "existing objects never move" is only true if a
     * photo's address comes from what was recorded when it was written, not
     * from today's configuration.
     *
     * @return array{0: string, 1: string} [shard tag, bucket name]
     *
     * @throws ShardUnavailable when the continent's env pair is unset -
     *                          the upload is refused, never redirected
     */
    public function activeFor(string $continent): array
    {
        $cc = strtoupper($continent);
        $shard = $this->activeShards[$cc] ?? '';
        $bucket = $this->activeBuckets[$cc] ?? '';
        if ('' === $shard || '' === $bucket) {
            throw new ShardUnavailable('' === $shard ? $cc : $shard);
        }

        return [strtoupper($shard), $bucket];
    }

    /* ---------- the quarantine (private storage) ---------- */

    /** @param resource|string $bytes */
    public function writeQuarantine(string $mediaId, mixed $bytes): void
    {
        if (\is_string($bytes)) {
            $this->filesystemFor($this->privateBucket)->write(self::QUARANTINE_PREFIX.$mediaId, $bytes);
        } else {
            $this->filesystemFor($this->privateBucket)->writeStream(self::QUARANTINE_PREFIX.$mediaId, $bytes);
        }
    }

    /**
     * Half of the release handler's no-op guard: a redelivered message after a
     * successful release finds nothing here and stops
     * (docs/specs/media-storage-architecture.md §3).
     */
    public function quarantineExists(string $mediaId): bool
    {
        try {
            return $this->filesystemFor($this->privateBucket)->fileExists(self::QUARANTINE_PREFIX.$mediaId);
        } catch (FilesystemException) {
            return false;
        }
    }

    /**
     * The quarantined bytes, or null when they are gone.
     *
     * A string rather than a stream, deliberately: the next thing that happens
     * to these bytes is an Imagick decode of the whole blob, and the byte cap
     * that bounds them (PhotoProcessor::MAX_BYTES, 15 MB) is enforced before
     * they are ever written. Streaming into a buffer we immediately materialise
     * would buy nothing.
     */
    public function readQuarantine(string $mediaId): ?string
    {
        try {
            return $this->filesystemFor($this->privateBucket)->read(self::QUARANTINE_PREFIX.$mediaId);
        } catch (FilesystemException) {
            return null;
        }
    }

    /** Idempotent: the release path and the disposal path both call it. */
    public function deleteQuarantine(string $mediaId): void
    {
        try {
            $this->filesystemFor($this->privateBucket)->delete(self::QUARANTINE_PREFIX.$mediaId);
        } catch (FilesystemException) {
            // Already gone. Nothing to undo, nothing to report.
        }
    }

    /* ---------- published objects (public storage) ---------- */

    public function store(string $bucket, string $prefix, ProcessedPhoto $photo): void
    {
        $filesystem = $this->filesystemFor($bucket);
        $filesystem->write($prefix.'/orig.webp', $photo->orig);
        $filesystem->write($prefix.'/lg.webp', $photo->lg);
        $filesystem->write($prefix.'/sm.webp', $photo->sm);
    }

    /**
     * Copies whichever variants exist from one prefix to another inside the
     * same shard, and answers how many moved.
     *
     * The one-off backfill onto immutable keys is the only caller
     * (docs/specs/media-storage-architecture.md §4.1, MediaBackfillKeysCommand)
     * - a copy, not a move, so a failure halfway leaves the source intact and
     * the command can simply run again.
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
     * Removes every object under the prefix. Idempotent by design: disposal
     * runs from a garbage collector, a Trash action and an account deletion,
     * and none of them may fail because the objects are already gone.
     */
    public function deletePrefix(string $bucket, string $prefix): void
    {
        try {
            $this->filesystemFor($bucket)->deleteDirectory($prefix);
        } catch (FilesystemException) {
            // Already absent, or the shard is unreachable. The row-side
            // bookkeeping is the source of truth; a retry sweeps again.
        }
    }

    /**
     * Opens one stored variant for reading, or null when it is not there.
     *
     * A stream rather than a string: the caller is the data export, and a rider
     * with a hundred photos must not cost a hundred full-resolution images'
     * worth of memory. Absence is a normal answer — a tombstoned upload's row
     * outlives its objects (docs/specs/photo-uploads.md §6) — so it is returned,
     * not thrown.
     *
     * @return resource|null
     *
     * @api Called by DataExportService.
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

    public function url(string $shard, string $prefix, string $variant): string
    {
        self::assertVariant($variant);

        // The lowercase shard tag is the proxy-routed path segment; the
        // bucket name never appears in a URL (§2.0).
        return rtrim($this->publicBase, '/').'/'.strtolower($shard).'/'.$prefix.'/'.$variant.'.webp';
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
            // A row minted before the bucket column existed, or a caller bug.
            // Failing loudly beats writing into a guessed bucket.
            throw new ShardUnavailable('(no bucket recorded)');
        }
        if (isset($this->filesystems[$bucket])) {
            return $this->filesystems[$bucket];
        }
        if (null === $this->client) {
            // Test wiring: only preloaded in-memory buckets exist.
            throw new ShardUnavailable($bucket);
        }

        return $this->filesystems[$bucket] = new Filesystem(new AsyncAwsS3Adapter($this->client, $bucket));
    }
}
