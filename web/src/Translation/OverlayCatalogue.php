<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Per-locale overlay map backed by cache.app (translations.md §3).
 *
 * English and unknown locales always return [] without querying.
 *
 * @api
 */
class OverlayCatalogue
{
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
        if ('en' === $locale || !TranslationLimits::isTranslatableLocale($locale)) {
            return [];
        }

        /** @var array<string, string> $map */
        $map = $this->cache->get(
            'translation_overlay.'.$locale,
            function (ItemInterface $item) use ($locale): array {
                return $this->loader->load($locale);
            },
        );

        return $map;
    }

    public function invalidate(string $locale): void
    {
        $this->cache->delete('translation_overlay.'.$locale);
    }
}
