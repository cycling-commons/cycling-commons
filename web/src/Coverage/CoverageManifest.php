<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Coverage;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Coverage tile manifest reader (docs/specs/coverage-provider.md §4).
 * Failure returns null and never throws.
 *
 * @see docs/specs/coverage-provider.md §4
 *
 * @api
 */
final class CoverageManifest extends BucketManifest
{
    public function __construct(
        HttpClientInterface $http,
        CacheInterface $cache,
        LoggerInterface $logger,
        private readonly bool $tilesEnabled,
        string $manifestUrl,
    ) {
        parent::__construct($http, $cache, $logger, $manifestUrl);
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

    #[\Override]
    protected function needsManifest(): bool
    {
        return $this->tilesEnabled && '' !== $this->manifestUrl;
    }

    #[\Override]
    protected function validate(array $manifest): void
    {
        $url = $manifest['url'] ?? null;
        if (!\is_string($url) || '' === $url) {
            throw new \RuntimeException('coverage manifest carries no "url" key');
        }
    }

    /** Cache key from the manifest URL. `.v2` retires old-shaped entries. */
    #[\Override]
    protected function cacheKey(): string
    {
        return 'coverage.manifest.v2.'.hash('xxh128', $this->manifestUrl);
    }

    #[\Override]
    protected function unavailableMessage(): string
    {
        return 'Coverage manifest unavailable, map serves without coverage tiles.';
    }

    #[\Override]
    protected function cacheUnavailableMessage(): string
    {
        return 'Coverage manifest cache unavailable, map serves without coverage tiles.';
    }
}
