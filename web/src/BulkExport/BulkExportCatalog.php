<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\BulkExport;

use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Reads the newest snapshot's manifest for the export page, the `latest`
 * links and `/developers`, through the shared cache so a page view does not
 * cost a storage round trip. The snapshot files themselves are nginx's to
 * serve, straight from the bucket; nothing here reads them.
 *
 * The manifest is cached for {@see TTL} seconds. The builder drops it the
 * moment it publishes ({@see forget()}); it runs on the worker host, which
 * shares the cache with the web frontends.
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

    /** Bumped whenever the cached shape or the storage layout changes. */
    private const string KEY = 'bulk_export.catalog.v3.';

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

    /** Drops the newest manifest: the next read sees what storage holds now. */
    public function forget(): void
    {
        $this->cache->delete(self::KEY.'latest');
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
