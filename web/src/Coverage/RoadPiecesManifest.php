<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Coverage;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Road-piece tile manifest: every way a bike may ride, labelled cycle path,
 * cycle lane or shared road, which Scout's ride review matches a ride against
 * in the browser. Per-country archives only; a pin serves one world entry.
 * Failure returns an empty array and never throws.
 *
 * @see docs/specs/traffic-measurements.md §2
 *
 * @api
 */
final class RoadPiecesManifest extends BucketManifest
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
            return self::worldEntry(['roadpieces' => $this->pinnedTilesUrl], '');
        }
        $manifest = $this->manifest();

        return null === $manifest ? [] : self::countryEntries($manifest, ['roadpieces']);
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
        if (2 !== ($manifest['version'] ?? null) || !\is_array($countries) || [] === $countries) {
            throw new \RuntimeException('road-piece manifest names no country');
        }
    }

    #[\Override]
    protected function cacheKey(): string
    {
        return 'roadpieces.manifest.v2.'.hash('xxh128', $this->manifestUrl);
    }

    #[\Override]
    protected function unavailableMessage(): string
    {
        return 'Road-piece manifest unavailable, ride review sends no traffic summaries.';
    }

    #[\Override]
    protected function cacheUnavailableMessage(): string
    {
        return 'Road-piece manifest cache unavailable, ride review sends no traffic summaries.';
    }
}
