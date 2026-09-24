<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Coverage;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Road-surface tile manifest (docs/specs/coverage-provider.md §4).
 * Per-country classified and to-do archives plus one world gap grid. A v1
 * manifest or a pin is served as one world entry. Env pins win when set.
 * Failure returns an empty array/null and never throws.
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

    /** @return array<string, array{tiles: array<string, string>, bounds: list<float>, stamp: string}> */
    public function countryTiles(): array
    {
        if ($this->pinned()) {
            return self::worldEntry(['classified' => $this->pinnedClassifiedUrl, 'todo' => $this->pinnedTodoUrl], '');
        }
        $manifest = $this->manifest();
        if (null === $manifest) {
            return [];
        }
        if (2 === ($manifest['version'] ?? null)) {
            return self::countryEntries($manifest, ['classified', 'todo']);
        }
        $tiles = $manifest['tiles'] ?? [];

        return self::worldEntry(array_filter([
            'classified' => \is_string($tiles['classified'] ?? null) ? $tiles['classified'] : '',
            'todo' => \is_string($tiles['todo'] ?? null) ? $tiles['todo'] : '',
        ]), \is_string($manifest['stamp'] ?? null) ? $manifest['stamp'] : '');
    }

    /** @return array{url: string, stamp: string}|null */
    public function gaps(): ?array
    {
        if ($this->pinned()) {
            return '' === $this->pinnedGapsUrl ? null : ['url' => $this->pinnedGapsUrl, 'stamp' => ''];
        }
        $manifest = $this->manifest();
        $url = 2 === ($manifest['version'] ?? null) ? ($manifest['gaps']['url'] ?? null) : ($manifest['tiles']['gaps'] ?? null);
        if (!\is_string($url) || '' === $url) {
            return null;
        }

        return ['url' => $url, 'stamp' => self::stampOf($url)];
    }

    /** Any pin puts the whole family on pins: a bisect names every arm it wants. */
    private function pinned(): bool
    {
        return '' !== $this->pinnedClassifiedUrl || '' !== $this->pinnedTodoUrl || '' !== $this->pinnedGapsUrl;
    }

    /**
     * The bucket is still needed while any arm is unpinned. Pinning all three
     * is the bisect hatch, and it must work on an installation with no
     * manifest at all.
     */
    #[\Override]
    protected function needsManifest(): bool
    {
        return '' !== $this->manifestUrl && !$this->pinned();
    }

    #[\Override]
    protected function validate(array $manifest): void
    {
        $countries = $manifest['countries'] ?? null;
        if (\is_array($countries) && [] !== $countries) {
            return;
        }
        $tiles = $manifest['tiles'] ?? null;
        if (!\is_array($tiles) || !array_filter(
            array_map(static fn (string $arm): mixed => $tiles[$arm] ?? null, self::ARMS),
            'is_string',
        )) {
            throw new \RuntimeException('surface manifest names no tile URLs');
        }
    }

    /** Cache key from the manifest URL. `.v2` retires old-shaped entries. */
    #[\Override]
    protected function cacheKey(): string
    {
        return 'surface.manifest.v2.'.hash('xxh128', $this->manifestUrl);
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
