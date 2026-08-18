<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

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
 * A SHARD, not a continent. The two are the same string today (one bucket per
 * continent), and they stop being the same the moment §2.1's numbered buckets
 * arrive. A continent with no storage of its own REFUSES the upload
 * (ShardUnavailable; owner 2026-08-18: "storage must fail") rather than
 * borrowing the default bucket: the borrow would scatter a region's photos
 * across shards and turn the eventual bucket's arrival into a migration.
 * shardFor() answers where the bytes will actually go, and that answer is
 * what the row stores.
 *
 * The browser-facing base is looked up PER SHARD rather than assembled by
 * concatenating one base with a shard segment, because no single string
 * expresses both deployments: in production the shard is a path segment the
 * owner-run proxy routes on, while against raw MinIO in dev it is baked into
 * the bucket name. Shards with no entry of their own fall back to
 * <publicBase>/<shard>, which is the production shape.
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

    /**
     * @param array<string, FilesystemOperator> $storages    shard code => public storage
     * @param array<string, string>             $publicBases shard code => browser-facing base URL
     */
    public function __construct(
        private readonly array $storages,
        private readonly array $publicBases,
        private readonly FilesystemOperator $private,
        private readonly string $publicBase,
    ) {
    }

    /**
     * Which shard a continent's photos are written to right now.
     *
     * Called once, at intake, and the answer is STORED on the row. Never
     * re-derived afterwards: §2.1's promise that existing objects never move
     * when a bucket is added is only true if a photo's address comes from what
     * was recorded when it was written, not from today's configuration.
     *
     * @throws ShardUnavailable when the continent has no provisioned bucket -
     *                          the upload is refused, never redirected
     */
    public function shardFor(string $continent): string
    {
        $code = strtoupper($continent);
        if (!isset($this->storages[$code])) {
            throw new ShardUnavailable($code);
        }

        return $code;
    }

    /* ---------- the quarantine (private storage) ---------- */

    /** @param resource|string $bytes */
    public function writeQuarantine(string $mediaId, mixed $bytes): void
    {
        if (\is_string($bytes)) {
            $this->private->write(self::QUARANTINE_PREFIX.$mediaId, $bytes);
        } else {
            $this->private->writeStream(self::QUARANTINE_PREFIX.$mediaId, $bytes);
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
            return $this->private->fileExists(self::QUARANTINE_PREFIX.$mediaId);
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
            return $this->private->read(self::QUARANTINE_PREFIX.$mediaId);
        } catch (FilesystemException) {
            return null;
        }
    }

    /** Idempotent: the release path and the disposal path both call it. */
    public function deleteQuarantine(string $mediaId): void
    {
        try {
            $this->private->delete(self::QUARANTINE_PREFIX.$mediaId);
        } catch (FilesystemException) {
            // Already gone. Nothing to undo, nothing to report.
        }
    }

    /* ---------- published objects (public storage) ---------- */

    public function store(string $shard, string $prefix, ProcessedPhoto $photo): void
    {
        $filesystem = $this->filesystemFor($shard);
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
    public function copyVariants(string $shard, string $fromPrefix, string $toPrefix): int
    {
        $filesystem = $this->filesystemFor($shard);
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
    public function deletePrefix(string $shard, string $prefix): void
    {
        try {
            $this->filesystemFor($shard)->deleteDirectory($prefix);
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
    public function readStream(string $shard, string $prefix, string $variant)
    {
        self::assertVariant($variant);

        try {
            return $this->filesystemFor($shard)->readStream($prefix.'/'.$variant.'.webp');
        } catch (FilesystemException) {
            return null;
        }
    }

    public function url(string $shard, string $prefix, string $variant): string
    {
        self::assertVariant($variant);

        $code = strtoupper($shard);
        $base = $this->publicBases[$code]
            ?? rtrim($this->publicBase, '/').'/'.strtolower($code);

        return rtrim($base, '/').'/'.$prefix.'/'.$variant.'.webp';
    }

    private static function assertVariant(string $variant): void
    {
        if (!\in_array($variant, self::VARIANTS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown photo variant "%s".', $variant));
        }
    }

    private function filesystemFor(string $shard): FilesystemOperator
    {
        // The shard on a row was validated at intake; one missing here means
        // a storage was removed from config while rows still point at it.
        // Failing loudly beats silently writing into another shard's bucket.
        $code = strtoupper($shard);

        return $this->storages[$code] ?? throw new ShardUnavailable($code);
    }
}
