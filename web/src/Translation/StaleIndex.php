<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Which entries are stale for a locale (translations.md §3.3).
 *
 * Overlay present: stale when overlay.english_version < entry.english_version.
 * No overlay: stale when entry.yaml_english_version < entry.english_version.
 *
 * @api
 */
final class StaleIndex
{
    /**
     * The one stale predicate (translations.md §3.3).
     *
     * Aliases `e` (translation_entry) and `o` (translation_overlay, LEFT
     * JOINed on the locale). {@see CatalogueBrowser} uses this same constant:
     * the two carried byte-identical copies until 2026-08-31, and they had
     * already drifted on the protected-key exclusion, which is why there is
     * only one now.
     */
    public const string PREDICATE_SQL = self::MADE_AGAINST_SQL.' < e.english_version';

    /**
     * Protected keys are excluded here as they are in {@see CatalogueBrowser}:
     * a consent contract changes by a VERSION bump in code and nowhere else
     * ({@see ProtectedKeys}), so listing one as work a translator can pick up,
     * or counting it in the account chip's badge, would be an item nobody can
     * ever act on.
     */
    private const string STALE_SQL = 'SELECT e.id, e.message_key, e.english, e.english_version, '
        .self::MADE_AGAINST_SQL.' AS made_against
        FROM translation_entry e
        LEFT JOIN translation_overlay o ON o.entry_id = e.id AND o.locale = :locale
        WHERE e.absent_at IS NULL
          AND e.message_key NOT IN (:protected)
          AND '.self::PREDICATE_SQL.'
        ORDER BY e.english_version DESC, e.message_key ASC';

    /** The English version a locale's wording was last made against. */
    private const string MADE_AGAINST_SQL = 'COALESCE(o.english_version, e.yaml_english_version)';

    public function __construct(
        private readonly Connection $db,
        private readonly CacheInterface $cache,
    ) {
    }

    /** @return array<int, true> */
    public function ids(string $locale): array
    {
        if (!TranslationLimits::isTranslatableLocale($locale)) {
            return [];
        }

        return $this->cache->get(
            'translation_stale.'.$locale,
            function (ItemInterface $_item) use ($locale): array {
                $ids = [];
                foreach ($this->db->fetchFirstColumn(
                    self::STALE_SQL,
                    ['locale' => $locale, 'protected' => ProtectedKeys::KEYS],
                    ['protected' => ArrayParameterType::STRING],
                ) as $id) {
                    $ids[(int) $id] = true;
                }

                return $ids;
            },
        );
    }

    public function isStale(int $entryId, string $locale): bool
    {
        return isset($this->ids($locale)[$entryId]);
    }

    /** @return list<array{id: int, message_key: string, english: string, english_version: int, made_against: int}> */
    public function listFor(string $locale): array
    {
        if (!TranslationLimits::isTranslatableLocale($locale)) {
            return [];
        }
        $rows = [];
        foreach ($this->db->fetchAllAssociative(
            self::STALE_SQL,
            ['locale' => $locale, 'protected' => ProtectedKeys::KEYS],
            ['protected' => ArrayParameterType::STRING],
        ) as $r) {
            $rows[] = [
                'id' => (int) $r['id'],
                'message_key' => (string) $r['message_key'],
                'english' => (string) $r['english'],
                'english_version' => (int) $r['english_version'],
                'made_against' => (int) $r['made_against'],
            ];
        }

        return $rows;
    }

    public function invalidate(string $locale): void
    {
        $this->cache->delete('translation_stale.'.$locale);
    }
}
