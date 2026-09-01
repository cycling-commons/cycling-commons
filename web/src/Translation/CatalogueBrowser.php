<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation;

use App\Entity\User;
use App\Pagination\Pager;
use App\Translation\Entity\TranslationEntry;
use App\Translation\Entity\TranslationOverlay;
use App\Translation\Entity\TranslationProposal;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Searchable English catalogue browser for /translate (translations.md §4).
 *
 * Live string = overlay ?? YAML default. YAML is read through the decorator
 * inner translator so overlays are not applied twice.
 *
 * @api
 */
final class CatalogueBrowser
{
    public const int PER_PAGE = 25;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly OverlayCatalogue $overlays,
        private readonly TranslatorInterface $yamlTranslator,
        private readonly Security $security,
    ) {
    }

    /**
     * @return array{
     *     rows: list<array{id: int, message_key: string, english: string, yaml_default: string, live: string, has_pending: bool, stale: bool, english_version: int, made_against: int}>,
     *     pager: array{page: int, pages: int, total: int, perPage: int, offset: int, prev: ?int, next: ?int}
     * }
     */
    public function search(string $q, string $locale, int $page, int $perPage, bool $staleOnly = false): array
    {
        // The consent contracts are not offered: see ProtectedKeys.
        $where = ['e.absent_at IS NULL', 'e.message_key NOT IN (:protected)'];
        $params = ['protected' => ProtectedKeys::KEYS];
        $types = ['protected' => ArrayParameterType::STRING];

        $q = trim($q);
        if ('' !== $q) {
            // ILIKE wildcards escaped in the bound value, never concatenated into SQL.
            $where[] = '(e.message_key ILIKE :q OR e.english ILIKE :q)';
            $params['q'] = '%'.$this->escapeIlike($q).'%';
        }

        // One copy of the stale predicate, shared with StaleIndex: the two
        // had already drifted on the protected-key exclusion (translations.md
        // §3.3). The aliases `e` and `o` below are what it expects.
        $staleSql = StaleIndex::PREDICATE_SQL;
        $from = 'translation_entry e LEFT JOIN translation_overlay o ON o.entry_id = e.id AND o.locale = :locale';
        $params['locale'] = $locale;
        if ($staleOnly) {
            $where[] = $staleSql;
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM {$from} WHERE {$whereSql}", $params, $types);
        $pager = Pager::of($page, $total, $perPage);

        /** @var list<array{id: int|string, message_key: string, english: string, english_yaml: string, english_version: int|string, made_against: int|string, stale: bool|string, overlay_value: ?string}> $raw */
        $raw = $this->db->fetchAllAssociative(
            "SELECT e.id, e.message_key, e.english, e.english_yaml, e.english_version,
                    COALESCE(o.english_version, e.yaml_english_version) AS made_against,
                    ({$staleSql}) AS stale, o.value AS overlay_value
             FROM {$from}
             WHERE {$whereSql}
             ORDER BY ({$staleSql}) DESC, e.message_key ASC
             LIMIT {$pager['perPage']} OFFSET {$pager['offset']}",
            $params,
            $types,
        );

        $entryIds = array_map(static fn (array $r): int => (int) $r['id'], $raw);
        $pendingIds = $this->pendingEntryIds($locale, $entryIds);

        $isEnglish = 'en' === $locale;
        $rows = [];
        foreach ($raw as $row) {
            $key = $row['message_key'];
            $id = (int) $row['id'];
            $yamlDefault = $isEnglish ? (string) $row['english_yaml'] : $this->yamlTranslator->trans($key, [], 'messages', $locale);
            $rows[] = [
                'id' => $id,
                'message_key' => $key,
                'english' => (string) $row['english'],
                'yaml_default' => $yamlDefault,
                'live' => $isEnglish ? (string) $row['english'] : ((string) ($row['overlay_value'] ?? $yamlDefault)),
                'has_pending' => isset($pendingIds[$id]),
                'stale' => !$isEnglish && (bool) $row['stale'],
                'english_version' => (int) $row['english_version'],
                'made_against' => (int) $row['made_against'],
            ];
        }

        return ['rows' => $rows, 'pager' => $pager];
    }

    /**
     * @return array{yaml_default: string, live: string, stale: bool, english_version: int, made_against: int}
     */
    public function liveFor(TranslationEntry $entry, string $locale): array
    {
        $key = $entry->getMessageKey();
        if ('en' === $locale) {
            return [
                'yaml_default' => $entry->getEnglishYaml(),
                'live' => $entry->getEnglish(),
                'stale' => false,
                'english_version' => $entry->getEnglishVersion(),
                'made_against' => $entry->getEnglishVersion(),
            ];
        }
        $yamlDefault = $this->yamlTranslator->trans($key, [], 'messages', $locale);
        $overlayMap = $this->overlays->map($locale);
        $overlay = $this->em->getRepository(TranslationOverlay::class)->findOneBy(['entry' => $entry, 'locale' => $locale]);
        $madeAgainst = null !== $overlay ? $overlay->getEnglishVersion() : $entry->getYamlEnglishVersion();

        return [
            'yaml_default' => $yamlDefault,
            'live' => $overlayMap[$key] ?? $yamlDefault,
            'stale' => $madeAgainst < $entry->getEnglishVersion(),
            'english_version' => $entry->getEnglishVersion(),
            'made_against' => $madeAgainst,
        ];
    }

    /**
     * True when `english_yaml` (what the last `app:translations:sync` read)
     * no longer matches what `messages.en.yaml` holds right now: English
     * moved in git (or an editor's working copy) and nobody has re-run the
     * sync since (translations.md §3.4, rule 7). Read through the same
     * decorator-inner translator `search()`/`liveFor()` use, never a fresh
     * file read, so this can never disagree with what the rest of the
     * browser already shows for the key.
     */
    public function englishSourceDrifted(TranslationEntry $entry): bool
    {
        $current = $this->yamlTranslator->trans($entry->getMessageKey(), [], 'messages', 'en');

        return $current !== $entry->getEnglishYaml();
    }

    private function escapeIlike(string $value): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value,
        );
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, true>
     */
    private function pendingEntryIds(string $locale, array $entryIds): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || [] === $entryIds) {
            return [];
        }

        /** @var list<int|string> $pending */
        $pending = $this->em->createQueryBuilder()
            ->select('IDENTITY(p.entry)')
            ->from(TranslationProposal::class, 'p')
            ->where('p.submitterId = :uid')
            ->andWhere('p.locale = :locale')
            ->andWhere('IDENTITY(p.entry) IN (:ids)')
            ->andWhere('p.status IN (:statuses)')
            ->setParameter('uid', (int) $user->getId())
            ->setParameter('locale', $locale)
            ->setParameter('ids', $entryIds)
            ->setParameter('statuses', [
                TranslationProposalStatus::Pending,
                TranslationProposalStatus::NeedsInfo,
            ])
            ->getQuery()
            ->getSingleColumnResult();

        $out = [];
        foreach ($pending as $id) {
            $out[(int) $id] = true;
        }

        return $out;
    }
}
