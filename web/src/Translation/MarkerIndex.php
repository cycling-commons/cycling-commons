<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Translation;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * message_key => [entry id, stale] for one locale (translations.md §4.1).
 *
 * What is absent from the map is what {@see MarkedTranslator} leaves unmarked:
 * a key with no catalogue row, a row marked absent, and every
 * {@see ProtectedKeys} key, because a consent contract must not be editable
 * from the page it is printed on.
 *
 * Cached with the overlay map; {@see TranslationCaches} invalidates it.
 *
 * @api
 */
final class MarkerIndex
{
    private const string KEYS_SQL = <<<'SQL'
        SELECT message_key, id
        FROM translation_entry
        WHERE absent_at IS NULL
          AND message_key NOT IN (:protected)
        SQL;

    /**
     * Per-request memo, locale => the map the cache answered with.
     *
     * NOT redundant with the cache pool, and removing it costs production
     * dearly: {@see MarkedTranslator::trans()} calls forLocale() once per
     * TRANSLATED STRING, and `cache.app` is the Redis adapter in production
     * (`config/packages/cache.yaml`). Without this, one translate-mode page
     * render is hundreds of Redis round trips, each deserialising the whole
     * map of roughly 3,800 keys. The test environment overrides `cache.app`
     * with the array adapter, so no test can ever show the cost: the first
     * person to feel it would be a curator on production.
     *
     * Lives for one request (the service is not shared across requests) and
     * is dropped per locale by {@see invalidate()}, so a writer that commits
     * and then re-reads inside the same request sees the new map.
     *
     * @var array<string, array<string, array{0: int, 1: bool}>>
     */
    private array $memo = [];

    public function __construct(
        private readonly Connection $db,
        private readonly StaleIndex $stale,
        private readonly CacheInterface $cache,
    ) {
    }

    /** @return array<string, array{0: int, 1: bool}> */
    public function forLocale(string $locale): array
    {
        if (!TranslationLimits::isOverlayLocale($locale)) {
            return [];
        }
        if (isset($this->memo[$locale])) {
            return $this->memo[$locale];
        }

        return $this->memo[$locale] = $this->cache->get(
            'translation_marker.'.$locale,
            function (ItemInterface $_item) use ($locale): array {
                // English is never stale: StaleIndex answers [] for it.
                $stale = $this->stale->ids($locale);
                $out = [];
                foreach ($this->db->fetchAllKeyValue(
                    self::KEYS_SQL,
                    ['protected' => ProtectedKeys::KEYS],
                    ['protected' => ArrayParameterType::STRING],
                ) as $key => $id) {
                    $out[(string) $key] = [(int) $id, isset($stale[(int) $id])];
                }

                return $out;
            },
        );
    }

    public function invalidate(string $locale): void
    {
        unset($this->memo[$locale]);
        $this->cache->delete('translation_marker.'.$locale);
    }
}
