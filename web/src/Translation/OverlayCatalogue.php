<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Translation;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Per-locale overlay map backed by cache.app (translations.md §3).
 *
 * English is an overlay locale (translations.md §3): only unknown locales
 * always return [] without querying.
 *
 * @api
 */
class OverlayCatalogue
{
    /**
     * Per-request memo, locale => the map the cache answered with.
     *
     * The same reasoning as {@see MarkerIndex::$memo}, and deliberately the
     * same five lines: {@see OverlayTranslator::trans()} calls map() once per
     * TRANSLATED STRING, and `cache.app` is the Redis adapter in production
     * (`config/packages/cache.yaml`), so without this a page render is one
     * Redis round trip per string. This map is normally small, so the cost is
     * smaller than MarkerIndex's, but leaving one of the pair unmemoised
     * would only invite somebody to remove the other as redundant.
     *
     * Lives for one request and is dropped per locale by {@see invalidate()}.
     *
     * @var array<string, array<string, string>>
     */
    private array $memo = [];

    public function __construct(
        private readonly OverlayCatalogueLoader $loader,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return array<string, string> message_key => value
     */
    public function map(string $locale): array
    {
        if (!TranslationLimits::isOverlayLocale($locale)) {
            return [];
        }
        if (isset($this->memo[$locale])) {
            return $this->memo[$locale];
        }

        return $this->memo[$locale] = $this->cache->get(
            'translation_overlay.'.$locale,
            function (ItemInterface $_item) use ($locale): array {
                return $this->loader->load($locale);
            },
        );
    }

    public function invalidate(string $locale): void
    {
        unset($this->memo[$locale]);
        $this->cache->delete('translation_overlay.'.$locale);
    }
}
