<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\BulkExport;

use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Reads the published snapshots for the export pages and the downloads,
 * through the shared cache so a page view does not cost a storage round trip.
 *
 * Three things are cached for {@see TTL} seconds: the newest manifest, the
 * list of known snapshots (those whose manifest is in storage, plus the newest
 * one), and the manifest of each known snapshot. The builder drops the first
 * two the moment it publishes or prunes ({@see forget()}); it runs on the
 * worker host, which shares the cache with the web frontends.
 *
 * A stamp is resolved only against the known list. Any other stamp, however
 * well formed, is answered from that one cached list: no storage call, and no
 * cache entry of its own, so a loop over made-up addresses costs neither the
 * bucket nor the Redis that holds every rate limiter.
 *
 * Storage that does not answer is not the same as nothing published: the
 * failure is remembered for {@see RETRY} seconds (the pages then say nothing
 * is published), and logged as a warning at most once per
 * {@see WARN_WINDOW}, not once per retry.
 *
 * @phpstan-import-type Manifest from BulkExportBuilder
 *
 * @api
 */
final class BulkExportCatalog
{
    private const int TTL = 600;

    /** How long a storage failure is remembered before the next attempt. */
    private const int RETRY = 60;

    /** At most one warning about failing storage per this many seconds. */
    private const int WARN_WINDOW = 3600;

    /** Bumped whenever the cached shape changes. */
    private const string KEY = 'bulk_export.catalog.v2.';

    public function __construct(
        private readonly BulkExportStorage $storage,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return Manifest|null the newest snapshot's manifest, or null while none is published */
    public function latest(): ?array
    {
        if (!$this->storage->isConfigured()) {
            return null;
        }

        return $this->manifest('latest', BulkExportStorage::LATEST);
    }

    /**
     * Whether the stamp names a published snapshot. Costs one cache read; a
     * storage listing only when the cached list has expired.
     */
    public function isKnown(string $stamp): bool
    {
        if (1 !== preg_match('/^'.BulkExportStorage::STAMP_PATTERN.'$/D', $stamp) || !$this->storage->isConfigured()) {
            return false;
        }

        return \in_array($stamp, $this->known(), true);
    }

    /** @return Manifest|null null unless the stamp is a known snapshot */
    public function snapshot(string $stamp): ?array
    {
        if (!$this->isKnown($stamp)) {
            return null;
        }
        $latest = $this->latest();
        if (null !== $latest && $latest['snapshot'] === $stamp) {
            return $latest;
        }

        return $this->manifest('snapshot.'.$stamp, $stamp.'/'.BulkExportBuilder::MANIFEST);
    }

    /** Drops the newest manifest and the known list: the next read sees what storage holds now. */
    public function forget(): void
    {
        $this->cache->delete(self::KEY.'latest');
        $this->cache->delete(self::KEY.'known');
    }

    /** @return list<string> */
    private function known(): array
    {
        $entry = $this->cache->get(self::KEY.'known', function (ItemInterface $item): array {
            $item->expiresAfter(self::TTL);
            try {
                return ['s' => $this->storage->published(), 'e' => null];
            } catch (FilesystemException $e) {
                $item->expiresAfter(self::RETRY);

                return ['s' => [], 'e' => $e->getMessage()];
            }
        });
        if (null !== $entry['e']) {
            $this->warn('snapshots/', $entry['e']);
        }
        $stamps = $entry['s'];
        // The newest snapshot is known even while the listing lags behind it.
        $latest = $this->latest();
        if (null !== $latest && !\in_array($latest['snapshot'], $stamps, true)) {
            $stamps[] = $latest['snapshot'];
        }

        return $stamps;
    }

    /**
     * The failure's message travels in the cached entry, so every read in the
     * retry window can ask {@see warn()}, which logs once per window.
     *
     * @return Manifest|null
     */
    private function manifest(string $key, string $path): ?array
    {
        $entry = $this->cache->get(self::KEY.$key, function (ItemInterface $item) use ($path): array {
            $item->expiresAfter(self::TTL);
            try {
                if (!$this->storage->has($path)) {
                    return ['m' => null, 'e' => null];
                }
                /** @var Manifest $manifest */
                $manifest = json_decode($this->storage->read($path), true, 512, \JSON_THROW_ON_ERROR);

                return ['m' => $manifest, 'e' => null];
            } catch (FilesystemException|\JsonException $e) {
                $item->expiresAfter(self::RETRY);

                return ['m' => null, 'e' => $e->getMessage()];
            }
        });
        if (null !== $entry['e']) {
            $this->warn($path, $entry['e']);
        }

        return $entry['m'];
    }

    /** Logs a storage failure, once per window however many reads fail in it. */
    private function warn(string $path, string $message): void
    {
        $this->cache->get(self::KEY.'warned', function (ItemInterface $item) use ($path, $message): bool {
            $item->expiresAfter(self::WARN_WINDOW);
            $this->logger->warning('Bulk export storage unreadable, the pages say nothing is published: {message}', ['message' => $message, 'path' => $path]);

            return true;
        });
    }
}
