<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;

/**
 * Writes, deletes and addresses photo objects across the per-continent
 * storages (docs/specs/photo-uploads.md §2). Object layout is
 * photos/<uuid>/orig.webp | lg.webp | sm.webp inside the continent's bucket.
 *
 * The browser-facing base is looked up PER CONTINENT rather than assembled by
 * concatenating one base with a continent segment, because no single string
 * expresses both deployments: in production the continent is a path segment the
 * owner-run proxy routes on, while against raw MinIO in dev it is baked into
 * the bucket name. Continents with no entry of their own fall back to
 * <publicBase>/<continent>, which is the production shape.
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
 * A continent with no storage configured yet writes to the default shard
 * rather than failing the rider's upload — onboarding a bucket is an
 * operations task, not a reason to drop a contribution.
 *
 * @api Called by MediaController, MediaDisposalService and MediaDecisionService.
 */
final class MediaStorage
{
    public const array VARIANTS = ['orig', 'lg', 'sm'];

    /**
     * @param array<string, FilesystemOperator> $storages    continent code => storage
     * @param array<string, string>             $publicBases continent code => browser-facing base URL
     */
    public function __construct(
        private readonly array $storages,
        private readonly array $publicBases,
        private readonly string $publicBase,
        private readonly string $defaultContinent,
    ) {
    }

    public function store(string $continent, string $prefix, ProcessedPhoto $photo): void
    {
        $filesystem = $this->filesystemFor($continent);
        $filesystem->write($prefix.'/orig.webp', $photo->orig);
        $filesystem->write($prefix.'/lg.webp', $photo->lg);
        $filesystem->write($prefix.'/sm.webp', $photo->sm);
    }

    /**
     * Removes every object under the prefix. Idempotent by design: disposal
     * runs from a garbage collector, a Trash action and an account deletion,
     * and none of them may fail because the objects are already gone.
     */
    public function deletePrefix(string $continent, string $prefix): void
    {
        try {
            $this->filesystemFor($continent)->deleteDirectory($prefix);
        } catch (FilesystemException) {
            // Already absent, or the shard is unreachable. The row-side
            // bookkeeping is the source of truth; a retry sweeps again.
        }
    }

    public function url(string $continent, string $prefix, string $variant): string
    {
        if (!\in_array($variant, self::VARIANTS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown photo variant "%s".', $variant));
        }

        $code = strtoupper($continent);
        $base = $this->publicBases[$code]
            ?? rtrim($this->publicBase, '/').'/'.strtolower($code);

        return rtrim($base, '/').'/'.$prefix.'/'.$variant.'.webp';
    }

    private function filesystemFor(string $continent): FilesystemOperator
    {
        $code = strtoupper($continent);

        return $this->storages[$code]
            ?? $this->storages[strtoupper($this->defaultContinent)]
            ?? throw new \LogicException(\sprintf('No media storage configured for continent "%s" and no default storage either.', $code));
    }
}
