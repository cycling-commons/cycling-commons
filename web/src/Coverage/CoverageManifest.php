<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Coverage;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Server-side reader of the coverage tile manifest
 * (coverage-provider.md §4): the weekly pipeline uploads a
 * versioned PMTiles artifact plus a manifest at the stable key
 * coverage/manifest.json; this resolves the current versioned tile URL so
 * MapController can inject it as window.CC_COVERAGE_URL — no client manifest
 * fetch on boot, and a new artifact goes live within the cache TTL without a
 * deploy.
 *
 * Tolerant by design: the map must render without coverage tiles whenever
 * the flag is off, the manifest URL is unset, or the bucket is unreachable —
 * every failure path returns null (and logs a warning), never throws.
 *
 * @api Injected into MapController::map().
 */
final class CoverageManifest
{
    private const string CACHE_KEY = 'coverage.manifest.url';

    /** Manifest re-read interval (s) — coverage-provider.md §4 (CACHE_TTL, 3600 s). */
    private const int CACHE_TTL = 3600;

    /** Bucket fetch timeout (s) — a slow bucket must not stall a /map render. */
    private const int FETCH_TIMEOUT = 5;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly bool $tilesEnabled,
        private readonly string $manifestUrl,
    ) {
    }

    /** The current versioned .pmtiles URL, or null when coverage is off/unavailable. */
    public function currentTileUrl(): ?string
    {
        if (!$this->tilesEnabled || '' === $this->manifestUrl) {
            return null;
        }

        try {
            // A throwing callback caches nothing (cache contracts), so a
            // transient bucket failure is retried on the next request instead
            // of pinning "no tiles" for a full TTL.
            return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): string {
                $item->expiresAfter(self::CACHE_TTL);
                /** @var array<string, mixed> $manifest */
                $manifest = $this->http
                    ->request('GET', $this->manifestUrl, ['timeout' => self::FETCH_TIMEOUT])
                    ->toArray();
                $url = $manifest['url'] ?? null;
                if (!\is_string($url) || '' === $url) {
                    throw new \RuntimeException('coverage manifest carries no "url" key');
                }

                return $url;
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Coverage manifest unavailable — map serves without coverage tiles.', [
                'manifest_url' => $this->manifestUrl,
                'exception' => $e,
            ]);

            return null;
        }
    }
}
