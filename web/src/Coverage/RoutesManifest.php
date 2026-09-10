<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Coverage;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cycle-route tile manifest (docs/specs/coverage-provider.md §4).
 * Own manifest, not a surface arm: separate builds must not share URLs.
 * Failure returns null and never throws.
 *
 * @see docs/specs/coverage-provider.md §4
 *
 * @api
 */
final class RoutesManifest extends BucketManifest
{
    public function __construct(
        HttpClientInterface $http,
        CacheInterface $cache,
        LoggerInterface $logger,
        string $manifestUrl,
        private readonly string $pinnedTilesUrl = '',
    ) {
        parent::__construct($http, $cache, $logger, $manifestUrl);
    }

    /** The routes artifact's URL, corridors plus knooppunten, or null. */
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

    #[\Override]
    protected function needsManifest(): bool
    {
        return '' === $this->pinnedTilesUrl && '' !== $this->manifestUrl;
    }

    #[\Override]
    protected function validate(array $manifest): void
    {
        $tiles = $manifest['tiles'] ?? null;
        if (!\is_array($tiles) || !\is_string($tiles['routes'] ?? null)) {
            throw new \RuntimeException('routes manifest names no tile URL');
        }
    }

    /** Cache key from the manifest URL. `.v1` retires old-shaped entries. */
    #[\Override]
    protected function cacheKey(): string
    {
        return 'routes.manifest.v1.'.hash('xxh128', $this->manifestUrl);
    }

    #[\Override]
    protected function unavailableMessage(): string
    {
        return 'Routes manifest unavailable, map serves without the route layer.';
    }

    #[\Override]
    protected function cacheUnavailableMessage(): string
    {
        return 'Routes manifest cache unavailable, map serves without the route layer.';
    }
}
