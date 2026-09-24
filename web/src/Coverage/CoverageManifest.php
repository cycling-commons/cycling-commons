<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Coverage;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Coverage tile manifest reader (docs/specs/coverage-provider.md §4).
 * Failure returns an empty array and never throws.
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

    /**
     * Coverage-point tile entries, per country (docs/specs/coverage-provider.md §4).
     * `zz`, the unstamped bucket, is a valid entry here even though it is not
     * a country code countryCodes() will ever return.
     *
     * @return array<string, array{tiles: array<string, string>, bounds: list<float>, stamp: string}>
     */
    public function countryTiles(): array
    {
        $manifest = $this->manifest();
        if (null === $manifest) {
            return [];
        }
        if (2 === ($manifest['version'] ?? null)) {
            return self::countryEntries($manifest, ['points']);
        }
        $url = $manifest['url'] ?? null;

        return self::worldEntry(['points' => \is_string($url) ? $url : ''], self::stampOf(\is_string($url) ? $url : ''));
    }

    /**
     * Country codes the current tile artifact was built for (docs/specs/coverage-provider.md §4).
     *
     * @return list<string>
     */
    public function countryCodes(): array
    {
        $manifest = $this->manifest();
        if (null !== $manifest && 2 === ($manifest['version'] ?? null)) {
            return array_values(array_map(
                strtoupper(...),
                array_filter(array_keys($this->countryTiles()), static fn (string $k): bool => '*' !== $k && 'zz' !== $k),
            ));
        }
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
        $countries = $manifest['countries'] ?? null;
        if (\is_array($countries) && [] !== $countries) {
            return;
        }
        $url = $manifest['url'] ?? null;
        if (!\is_string($url) || '' === $url) {
            throw new \RuntimeException('coverage manifest carries no "url" key');
        }
    }

    /** Cache key from the manifest URL. `.v3` retires old-shaped entries. */
    #[\Override]
    protected function cacheKey(): string
    {
        return 'coverage.manifest.v3.'.hash('xxh128', $this->manifestUrl);
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
