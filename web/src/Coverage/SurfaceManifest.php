<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Coverage;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Server-side reader of the ROAD-SURFACE tile manifest — the sibling of
 * {@see CoverageManifest}, for the three line artifacts rather than the point
 * one.
 *
 * The surface build publishes `surface/<stamp>/{classified,todo,gaps}.pmtiles`
 * and repoints `surface/manifest.json` at them. Reading that here means a
 * rebuild goes live within the cache TTL: no env edit, no cache clear, no
 * deploy. Before this existed, every rebuild needed all three by hand, and the
 * failure mode was silent — a pinned URL left pointing at a pruned artifact is
 * a map with no surfaces and nothing in any log.
 *
 * **All three URLs come from one manifest, and that is the point.** The arms
 * are three readings of a single walk over the same ways. Serving one build's
 * classified skin beside another's to-do arm would tell riders that roads they
 * have just recorded still need recording — so the arms move together or not
 * at all.
 *
 * **The env vars still win when set.** SURFACE_TILES_URL / SURFACE_TODO_URL /
 * SURFACE_GAPS_URL pin a specific build, which is what you want when bisecting
 * a rendering problem or serving an artifact that was never published. Empty
 * (the default) means "follow the manifest".
 *
 * Tolerant by design, exactly as CoverageManifest is: every failure path
 * returns null, logs, and leaves the map rendering without the layer. A missing
 * surface layer is a smaller harm than a 500.
 *
 * @see docs/specs/coverage-provider.md §4
 *
 * @api Injected into MapController::map().
 */
final class SurfaceManifest
{
    /** How long, in seconds, the manifest stays cached before being re-read. */
    private const int CACHE_TTL = 3600;

    /** How long, in seconds, a failed fetch is remembered before retrying. */
    private const int NEGATIVE_TTL = 30;

    /** Bucket fetch bound, in seconds — a slow bucket must not stall a /map render. */
    private const int FETCH_TIMEOUT = 5;

    /** The arms a build publishes, in the order the client mounts them. */
    private const array ARMS = ['classified', 'todo', 'gaps'];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $manifestUrl,
        private readonly string $pinnedClassifiedUrl = '',
        private readonly string $pinnedTodoUrl = '',
        private readonly string $pinnedGapsUrl = '',
    ) {
    }

    /** The classified skin's URL — what is under your tyres — or null. */
    public function classifiedUrl(): ?string
    {
        return $this->url('classified', $this->pinnedClassifiedUrl);
    }

    /** The "still to record" arm's URL, or null. */
    public function todoUrl(): ?string
    {
        return $this->url('todo', $this->pinnedTodoUrl);
    }

    /** The gap grid's URL, or null. */
    public function gapsUrl(): ?string
    {
        return $this->url('gaps', $this->pinnedGapsUrl);
    }

    /**
     * One arm's URL: the operator's pin if there is one, else the manifest's.
     *
     * A pin is honoured WITHOUT fetching the manifest at all, so an instance
     * that pins all three never touches the bucket — which is what makes this
     * safe to add to an installation that has no manifest yet.
     */
    private function url(string $arm, string $pinned): ?string
    {
        if ('' !== $pinned) {
            return $pinned;
        }
        $tiles = $this->manifest()['tiles'] ?? null;
        if (!\is_array($tiles)) {
            return null;
        }
        $url = $tiles[$arm] ?? null;

        return \is_string($url) && '' !== $url ? $url : null;
    }

    /**
     * The decoded manifest, or null when it is unset/unreachable/malformed.
     *
     * Cached whole, so one bucket fetch per holdoff window serves all three
     * arms rather than three fetches for one build.
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
                    if (!\is_array($tiles) || !array_filter(
                        array_map(static fn (string $arm): mixed => $tiles[$arm] ?? null, self::ARMS),
                        'is_string',
                    )) {
                        throw new \RuntimeException('surface manifest names no tile URLs');
                    }
                    $item->expiresAfter(self::CACHE_TTL);

                    return $manifest;
                } catch (\Throwable $e) {
                    $item->expiresAfter(self::NEGATIVE_TTL);
                    $this->logger->warning('Surface manifest unavailable — map serves without the surface layers.', [
                        'manifest_url' => $this->manifestUrl,
                        'exception' => $e,
                    ]);

                    return null;
                }
            });
        } catch (\Throwable $e) {
            // The cache backend itself failed. Same silent degradation.
            $this->logger->warning('Surface manifest cache unavailable — map serves without the surface layers.', [
                'manifest_url' => $this->manifestUrl,
                'exception' => $e,
            ]);

            return null;
        }
    }

    /**
     * Cache key derived from the manifest URL (hex digest, PSR-6-safe), so
     * repointing SURFACE_MANIFEST_URL takes effect on the next request rather
     * than after CACHE_TTL. The shape segment retires old-shaped entries if the
     * cached value ever changes shape — bump it when that happens.
     */
    private function cacheKey(): string
    {
        return 'surface.manifest.v1.'.hash('xxh128', $this->manifestUrl);
    }
}
