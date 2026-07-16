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
 * Failures are negative-cached for NEGATIVE_TTL so a degraded bucket costs
 * one bounded fetch per holdoff window, not one per /map render.
 *
 * @api Injected into MapController::map().
 */
final class CoverageManifest
{
    /** Manifest re-read interval (s) — coverage-provider.md §4 (CACHE_TTL, 3600 s). */
    private const int CACHE_TTL = 3600;

    /**
     * Failure holdoff (s): coverage-provider.md §4 mandates null on every
     * failure path; this negative TTL is spec-neutral implementation
     * hardening on top — 30 s keeps a degraded bucket from costing a
     * FETCH_TIMEOUT-bounded fetch on every /map render under load, while
     * recovery is still picked up within half a minute.
     */
    private const int NEGATIVE_TTL = 30;

    /** Bucket fetch bound (s), both idle timeout and total max_duration — a slow (or slow-dripping) bucket must not stall a /map render. */
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
            return $this->cache->get($this->cacheKey(), function (ItemInterface $item): ?string {
                try {
                    /** @var array<string, mixed> $manifest */
                    $manifest = $this->http
                        ->request('GET', $this->manifestUrl, [
                            // Both bounds: 'timeout' caps idle time between
                            // chunks, 'max_duration' caps the whole request —
                            // a slow-drip host defeats the former alone.
                            'timeout' => self::FETCH_TIMEOUT,
                            'max_duration' => self::FETCH_TIMEOUT,
                        ])
                        ->toArray();
                    $url = $manifest['url'] ?? null;
                    if (!\is_string($url) || '' === $url) {
                        throw new \RuntimeException('coverage manifest carries no "url" key');
                    }
                    $item->expiresAfter(self::CACHE_TTL);

                    return $url;
                } catch (\Throwable $e) {
                    // Negative cache: null (coverage-provider.md §4's mandated
                    // failure result) held for NEGATIVE_TTL, then retried.
                    $item->expiresAfter(self::NEGATIVE_TTL);
                    $this->logger->warning('Coverage manifest unavailable — map serves without coverage tiles.', [
                        'manifest_url' => $this->manifestUrl,
                        'exception' => $e,
                    ]);

                    return null;
                }
            });
        } catch (\Throwable $e) {
            // The cache backend itself failed — same silent degradation.
            $this->logger->warning('Coverage manifest cache unavailable — map serves without coverage tiles.', [
                'manifest_url' => $this->manifestUrl,
                'exception' => $e,
            ]);

            return null;
        }
    }

    /**
     * Cache key derived from the manifest URL (hex digest, PSR-6-safe): an
     * operator repointing COVERAGE_MANIFEST_URL against a persistent pool
     * self-corrects on the next request instead of serving the previous
     * versioned URL for up to CACHE_TTL.
     */
    private function cacheKey(): string
    {
        return 'coverage.manifest.'.hash('xxh128', $this->manifestUrl);
    }
}
