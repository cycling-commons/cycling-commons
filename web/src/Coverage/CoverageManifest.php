<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Coverage;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Coverage tile manifest reader (docs/specs/coverage-provider.md §4).
 * Failure returns null and never throws.
 *
 * @see docs/specs/coverage-provider.md §4
 *
 * @api
 */
final class CoverageManifest
{
    /** How long, in seconds, the manifest URL stays cached before being re-read. */
    private const int CACHE_TTL = 3600;

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
        // Cached manifest is only non-null once 'url' has been validated.
        $url = $manifest['url'] ?? null;

        return \is_string($url) ? $url : null;
    }

    /**
     * Country codes the current tile artifact was built for (docs/specs/coverage-provider.md §4).
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
     * Decoded manifest, or null when coverage is off/unavailable.
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
                            // 'timeout' caps idle time; 'max_duration' caps the whole request.
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
            // Cache backend failed. Same silent degradation.
            $this->logger->warning('Coverage manifest cache unavailable — map serves without coverage tiles.', [
                'manifest_url' => $this->manifestUrl,
                'exception' => $e,
            ]);

            return null;
        }
    }

    /** Cache key from the manifest URL. `.v2` retires old-shaped entries. */
    private function cacheKey(): string
    {
        return 'coverage.manifest.v2.'.hash('xxh128', $this->manifestUrl);
    }
}
