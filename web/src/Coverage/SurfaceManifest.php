<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Coverage;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
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
final class SurfaceManifest extends BucketManifest
{
    /** The arms a build publishes, in the order the client mounts them. */
    private const array ARMS = ['classified', 'todo', 'gaps'];

    public function __construct(
        HttpClientInterface $http,
        CacheInterface $cache,
        LoggerInterface $logger,
        string $manifestUrl,
        private readonly string $pinnedClassifiedUrl = '',
        private readonly string $pinnedTodoUrl = '',
        private readonly string $pinnedGapsUrl = '',
    ) {
        parent::__construct($http, $cache, $logger, $manifestUrl);
    }

    /** The classified skin's URL, what is under your tyres, or null. */
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
     * The bucket is still needed while any arm is unpinned. Pinning all three
     * is the bisect hatch, and it must work on an installation with no
     * manifest at all.
     */
    #[\Override]
    protected function needsManifest(): bool
    {
        if ('' === $this->manifestUrl) {
            return false;
        }

        return '' === $this->pinnedClassifiedUrl
            || '' === $this->pinnedTodoUrl
            || '' === $this->pinnedGapsUrl;
    }

    #[\Override]
    protected function validate(array $manifest): void
    {
        $tiles = $manifest['tiles'] ?? null;
        if (!\is_array($tiles) || !array_filter(
            array_map(static fn (string $arm): mixed => $tiles[$arm] ?? null, self::ARMS),
            'is_string',
        )) {
            throw new \RuntimeException('surface manifest names no tile URLs');
        }
    }

    /** Cache key from the manifest URL. `.v1` retires old-shaped entries. */
    #[\Override]
    protected function cacheKey(): string
    {
        return 'surface.manifest.v1.'.hash('xxh128', $this->manifestUrl);
    }

    #[\Override]
    protected function unavailableMessage(): string
    {
        return 'Surface manifest unavailable, map serves without the surface layers.';
    }

    #[\Override]
    protected function cacheUnavailableMessage(): string
    {
        return 'Surface manifest cache unavailable, map serves without the surface layers.';
    }
}
