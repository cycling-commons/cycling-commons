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
 * Failure returns an empty array and never throws.
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

    /** @return array<string, array{tiles: array<string, string>, bounds: list<float>, stamp: string}> */
    public function countryTiles(): array
    {
        if ('' !== $this->pinnedTilesUrl) {
            return self::worldEntry(['routes' => $this->pinnedTilesUrl], '');
        }
        $manifest = $this->manifest();
        if (null === $manifest) {
            return [];
        }
        if (2 === ($manifest['version'] ?? null)) {
            return self::countryEntries($manifest, ['routes']);
        }
        $url = $manifest['tiles']['routes'] ?? null;

        return self::worldEntry(['routes' => \is_string($url) ? $url : ''], \is_string($manifest['stamp'] ?? null) ? $manifest['stamp'] : '');
    }

    #[\Override]
    protected function needsManifest(): bool
    {
        return '' === $this->pinnedTilesUrl && '' !== $this->manifestUrl;
    }

    #[\Override]
    protected function validate(array $manifest): void
    {
        $countries = $manifest['countries'] ?? null;
        if (\is_array($countries) && [] !== $countries) {
            return;
        }
        $tiles = $manifest['tiles'] ?? null;
        if (!\is_array($tiles) || !\is_string($tiles['routes'] ?? null)) {
            throw new \RuntimeException('routes manifest names no tile URL');
        }
    }

    /** Cache key from the manifest URL. `.v2` retires old-shaped entries. */
    #[\Override]
    protected function cacheKey(): string
    {
        return 'routes.manifest.v2.'.hash('xxh128', $this->manifestUrl);
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
