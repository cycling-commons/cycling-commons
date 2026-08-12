<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Coverage;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Server-side reader of the CYCLE-ROUTE network tile manifest — the sibling of
 * {@see SurfaceManifest}, for the routes artifact (route=bicycle/mtb corridors
 * plus knooppunt numbers, one PMTiles archive).
 *
 * The routes build publishes `routes/<stamp>/routes.pmtiles` and repoints
 * `routes/manifest.json` at it. One artifact, so this class is the one-arm
 * version of its sibling: same tolerant contract (every failure path returns
 * null, logs, and the map renders without the layer), same env-pin override
 * (ROUTES_TILES_URL wins when set, and is honoured without touching the
 * bucket), same TTLs.
 *
 * Its OWN manifest, deliberately not a fourth arm of the surface one: the two
 * builds run as separate invocations, and a shared manifest would let
 * whichever ran last publish half-updated URLs for the other's arms. The
 * route-awareness coupling between the two lives in the surface BUILD's inputs
 * (the way-id files), never in serving.
 *
 * @see docs/specs/coverage-provider.md §4
 *
 * @api Injected into MapController::map().
 */
final class RoutesManifest
{
    /** How long, in seconds, the manifest stays cached before being re-read. */
    private const int CACHE_TTL = 3600;

    /** How long, in seconds, a failed fetch is remembered before retrying. */
    private const int NEGATIVE_TTL = 30;

    /** Bucket fetch bound, in seconds — a slow bucket must not stall a /map render. */
    private const int FETCH_TIMEOUT = 5;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $manifestUrl,
        private readonly string $pinnedTilesUrl = '',
    ) {
    }

    /** The routes artifact's URL — corridors + knooppunten — or null. */
    public function tilesUrl(): ?string
    {
        if ('' !== $this->pinnedTilesUrl) {
            return $this->pinnedTilesUrl;
        }
        $tiles = $this->manifest()['tiles'] ?? null;
        if (!\is_array($tiles)) {
            return null;
        }
        $url = $tiles['routes'] ?? null;

        return \is_string($url) && '' !== $url ? $url : null;
    }

    /**
     * The decoded manifest, or null when it is unset/unreachable/malformed.
     *
     * @return array<string, mixed>|null
     */
    private function manifest(): ?array
    {
        if ('' === $this->manifestUrl) {
            return null;
        }

        try {
            return $this->cache->get($this->cacheKey(), function (ItemInterface $item): ?array {
                try {
                    /** @var array<string, mixed> $manifest */
                    $manifest = $this->http
                        ->request('GET', $this->manifestUrl, [
                            // 'timeout' caps idle time between chunks;
                            // 'max_duration' caps the whole request.
                            'timeout' => self::FETCH_TIMEOUT,
                            'max_duration' => self::FETCH_TIMEOUT,
                        ])
                        ->toArray();
                    $tiles = $manifest['tiles'] ?? null;
                    if (!\is_array($tiles) || !\is_string($tiles['routes'] ?? null)) {
                        throw new \RuntimeException('routes manifest names no tile URL');
                    }
                    $item->expiresAfter(self::CACHE_TTL);

                    return $manifest;
                } catch (\Throwable $e) {
                    $item->expiresAfter(self::NEGATIVE_TTL);
                    $this->logger->warning('Routes manifest unavailable — map serves without the route layer.', [
                        'manifest_url' => $this->manifestUrl,
                        'exception' => $e,
                    ]);

                    return null;
                }
            });
        } catch (\Throwable $e) {
            // The cache backend itself failed. Same silent degradation.
            $this->logger->warning('Routes manifest cache unavailable — map serves without the route layer.', [
                'manifest_url' => $this->manifestUrl,
                'exception' => $e,
            ]);

            return null;
        }
    }

    /**
     * Cache key derived from the manifest URL (hex digest, PSR-6-safe), so
     * repointing ROUTES_MANIFEST_URL takes effect on the next request rather
     * than after CACHE_TTL. The shape segment retires old-shaped entries if
     * the cached value ever changes shape — bump it when that happens.
     */
    private function cacheKey(): string
    {
        return 'routes.manifest.v1.'.hash('xxh128', $this->manifestUrl);
    }
}
