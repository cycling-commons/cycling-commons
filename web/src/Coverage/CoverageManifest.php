<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Coverage;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Server-side reader of the coverage tile manifest: the weekly pipeline
 * uploads a versioned PMTiles artifact plus a manifest at a stable key.
 * This resolves the current tile URL so MapController can inject it into
 * the page, with no client-side manifest fetch needed, and a new artifact
 * goes live within the cache TTL without a deploy.
 *
 * Tolerant by design: the map must render without coverage tiles whenever
 * the flag is off, the manifest URL is unset, or the bucket is unreachable.
 * Every failure path returns null and logs a warning; it never throws.
 * Failures are cached briefly, so a broken bucket costs one bounded fetch
 * per holdoff window, not one per /map render.
 *
 * @see docs/specs/coverage-provider.md §4
 *
 * @api Injected into MapController::map().
 */
final class CoverageManifest
{
    /** How long, in seconds, the manifest URL stays cached before being re-read. */
    private const int CACHE_TTL = 3600;

    /**
     * How long, in seconds, a failed fetch is remembered before retrying.
     * This keeps a broken bucket from costing a slow fetch on every /map
     * render, while still recovering within half a minute.
     */
    private const int NEGATIVE_TTL = 30;

    /** Bucket fetch bound, in seconds, used as both idle timeout and total max duration. A slow bucket must not stall a /map render. */
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
        $manifest = $this->manifest();
        // The cached manifest is only ever non-null once its 'url' key has been
        // validated as a non-empty string (see manifest()), so this is a plain read.
        $url = $manifest['url'] ?? null;

        return \is_string($url) ? $url : null;
    }

    /**
     * The country codes the current tile artifact was built for
     * (2026-07-22-coverage-scope-rendering-design.md §D), e.g. ['BE', 'NL'] —
     * the map client turns each into a per-country coverage layer. Empty when
     * coverage is off, the manifest is unreachable, or a pre-split manifest
     * carries no `country_codes` key (the client then falls back to a single
     * unsplit layer per letter). Only string members survive.
     *
     * @return list<string>
     */
    public function countryCodes(): array
    {
        $manifest = $this->manifest();
        $codes = $manifest['country_codes'] ?? null;
        if (!\is_array($codes)) {
            return [];
        }

        return array_values(array_filter($codes, 'is_string'));
    }

    /**
     * The decoded manifest array, or null when coverage is off/unavailable.
     * Cached (CACHE_TTL) as the whole decoded array — both currentTileUrl() and
     * countryCodes() derive from this single cached read, so one bucket fetch
     * per holdoff window serves both. A manifest with no valid 'url' is treated
     * as unavailable (null), same as before the array widening.
     *
     * @return array<string, mixed>|null
     */
    private function manifest(): ?array
    {
        if (!$this->tilesEnabled || '' === $this->manifestUrl) {
            return null;
        }

        try {
            return $this->cache->get($this->cacheKey(), function (ItemInterface $item): ?array {
                try {
                    /** @var array<string, mixed> $manifest */
                    $manifest = $this->http
                        ->request('GET', $this->manifestUrl, [
                            // 'timeout' caps idle time between chunks;
                            // 'max_duration' caps the whole request. A
                            // slow-drip host would defeat 'timeout' alone.
                            'timeout' => self::FETCH_TIMEOUT,
                            'max_duration' => self::FETCH_TIMEOUT,
                        ])
                        ->toArray();
                    $url = $manifest['url'] ?? null;
                    if (!\is_string($url) || '' === $url) {
                        throw new \RuntimeException('coverage manifest carries no "url" key');
                    }
                    $item->expiresAfter(self::CACHE_TTL);

                    return $manifest;
                } catch (\Throwable $e) {
                    // Cache the failure (null) for NEGATIVE_TTL, then retry.
                    $item->expiresAfter(self::NEGATIVE_TTL);
                    $this->logger->warning('Coverage manifest unavailable — map serves without coverage tiles.', [
                        'manifest_url' => $this->manifestUrl,
                        'exception' => $e,
                    ]);

                    return null;
                }
            });
        } catch (\Throwable $e) {
            // The cache backend itself failed. Same silent degradation as above.
            $this->logger->warning('Coverage manifest cache unavailable — map serves without coverage tiles.', [
                'manifest_url' => $this->manifestUrl,
                'exception' => $e,
            ]);

            return null;
        }
    }

    /**
     * Cache key derived from the manifest URL (hex digest, PSR-6-safe). If
     * an operator repoints COVERAGE_MANIFEST_URL while using a persistent
     * cache, the next request uses the new key, instead of serving the old
     * URL for up to CACHE_TTL.
     */
    private function cacheKey(): string
    {
        return 'coverage.manifest.'.hash('xxh128', $this->manifestUrl);
    }
}
