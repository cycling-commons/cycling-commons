<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Coverage;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Road-surface tile manifest (docs/specs/coverage-provider.md §4).
 * All three arms come from one manifest so they move together.
 * Env pins win when set. Failure returns null and never throws.
 *
 * @see docs/specs/coverage-provider.md §4
 *
 * @api
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

    /** One arm's URL: operator pin if set, else the manifest. A pin skips the bucket. */
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
     * Decoded manifest, or null when unset/unreachable/malformed.
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
                            // 'timeout' caps idle time; 'max_duration' caps the whole request.
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
            // Cache backend failed. Same silent degradation.
            $this->logger->warning('Surface manifest cache unavailable — map serves without the surface layers.', [
                'manifest_url' => $this->manifestUrl,
                'exception' => $e,
            ]);

            return null;
        }
    }

    /** Cache key from the manifest URL. `.v1` retires old-shaped entries. */
    private function cacheKey(): string
    {
        return 'surface.manifest.v1.'.hash('xxh128', $this->manifestUrl);
    }
}
