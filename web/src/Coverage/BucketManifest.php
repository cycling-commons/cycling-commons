<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Coverage;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Shared reader for the JSON manifests a tile build publishes to the bucket
 * (docs/specs/coverage-provider.md §4). One subclass per artifact family:
 * coverage, surface, routes.
 *
 * Every subclass keeps the same serving contract: a pin wins over the bucket,
 * a hit is cached for an hour, and any failure degrades to "no layer" rather
 * than a 500. Nothing here throws.
 *
 * ## Why prefetch() exists
 *
 * /map reads three manifests and /v1/map-config reads two. Read one after the
 * other, each waiting for the last, a slow bucket multiplies: three fetches
 * near the 5-second bound made map-config take 10.6 seconds on staging
 * (measured 2026-08-30), and the timeout is per fetch, so no single bound
 * caps the page. prefetch() starts a request and returns without waiting, so
 * a caller can start all of them and then read them in any order. The worst
 * case becomes one FETCH_TIMEOUT for the whole page instead of one per
 * manifest.
 *
 * A prefetch that turns out to be unnecessary costs nothing: it is skipped
 * when the value is already cached, and cancelled when a concurrent request
 * warms the cache first.
 *
 * @see docs/specs/coverage-provider.md §4
 */
abstract class BucketManifest
{
    /** How long, in seconds, a manifest stays cached before being re-read. */
    protected const int CACHE_TTL = 3600;

    /** How long, in seconds, a failed fetch is remembered before retrying. */
    protected const int NEGATIVE_TTL = 30;

    /** Bucket fetch bound, in seconds, used as both idle timeout and total max duration. A slow bucket must not stall a /map render. */
    protected const int FETCH_TIMEOUT = 5;

    /** A request started by prefetch() and not yet consumed by manifest(). */
    private ?ResponseInterface $pending = null;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        protected readonly string $manifestUrl,
    ) {
    }

    /**
     * Start the bucket fetch and return without waiting for it.
     *
     * Call this on every manifest a page needs, before reading any of them.
     * It is a no-op when the manifest is pinned or switched off, when the
     * value is already cached, or when a prefetch is already in flight.
     */
    final public function prefetch(): void
    {
        if (null !== $this->pending || !$this->needsManifest()) {
            return;
        }

        try {
            // Only the pool interface can answer "is it cached?" without
            // computing the value, which is the whole point here: a warm
            // cache must not pay for a bucket round trip.
            if ($this->cache instanceof CacheItemPoolInterface
                && $this->cache->getItem($this->cacheKey())->isHit()) {
                return;
            }

            $this->pending = $this->startFetch();
        } catch (\Throwable $e) {
            // A prefetch is an optimisation. Losing it means the fetch happens
            // inline instead, which is what used to happen anyway.
            $this->pending = null;
            $this->logger->warning($this->cacheUnavailableMessage(), [
                'manifest_url' => $this->manifestUrl,
                'exception' => $e,
            ]);
        }
    }

    /** Whether this manifest still has to be read, or a pin/flag makes it moot. */
    abstract protected function needsManifest(): bool;

    /**
     * Reject a manifest whose shape the caller cannot use. Throw to degrade.
     *
     * @param array<string, mixed> $manifest
     */
    abstract protected function validate(array $manifest): void;

    /** Cache key for this manifest, derived from its URL. */
    abstract protected function cacheKey(): string;

    /** Log line for a manifest that could not be fetched or parsed. */
    abstract protected function unavailableMessage(): string;

    /** Log line for a cache backend that could not answer. */
    abstract protected function cacheUnavailableMessage(): string;

    /**
     * The decoded manifest, or null when it is unset, unreachable or malformed.
     *
     * @return array<string, mixed>|null
     */
    final protected function manifest(): ?array
    {
        if (!$this->needsManifest()) {
            return null;
        }

        try {
            $manifest = $this->cache->get($this->cacheKey(), function (ItemInterface $item): ?array {
                // Take the prefetched request before touching it, so a failure
                // below cannot leave a dead response for the next caller.
                $response = $this->pending ?? $this->startFetch();
                $this->pending = null;

                try {
                    /** @var array<string, mixed> $manifest */
                    $manifest = $response->toArray();
                    $this->validate($manifest);
                    $item->expiresAfter(static::CACHE_TTL);

                    return $manifest;
                } catch (\Throwable $e) {
                    // Cache the failure (null) for NEGATIVE_TTL, then retry.
                    $item->expiresAfter(static::NEGATIVE_TTL);
                    $this->logger->warning($this->unavailableMessage(), [
                        'manifest_url' => $this->manifestUrl,
                        'exception' => $e,
                    ]);

                    return null;
                }
            });
        } catch (\Throwable $e) {
            // Cache backend failed. Same silent degradation.
            $this->discardPending();
            $this->logger->warning($this->cacheUnavailableMessage(), [
                'manifest_url' => $this->manifestUrl,
                'exception' => $e,
            ]);

            return null;
        }

        // The cache answered from a hit, so the prefetch was not needed.
        $this->discardPending();

        return $manifest;
    }

    /** A started, unconsumed request, bounded in both idle and total time. */
    private function startFetch(): ResponseInterface
    {
        return $this->http->request('GET', $this->manifestUrl, [
            // 'timeout' caps idle time; 'max_duration' caps the whole request.
            'timeout' => static::FETCH_TIMEOUT,
            'max_duration' => static::FETCH_TIMEOUT,
        ]);
    }

    /** Drop a prefetch nobody consumed, so its socket does not idle until GC. */
    private function discardPending(): void
    {
        $pending = $this->pending;
        $this->pending = null;
        $pending?->cancel();
    }
}
