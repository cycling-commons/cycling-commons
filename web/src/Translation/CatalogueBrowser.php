<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation;

use App\Entity\User;
use App\Pagination\Pager;
use App\Translation\Entity\TranslationEntry;
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
     *     rows: list<array{id: int, message_key: string, english: string, live: string, has_pending: bool}>,
     *     pager: array{page: int, pages: int, total: int, perPage: int, offset: int, prev: ?int, next: ?int}
     * }
     */
    public function search(string $q, string $locale, int $page, int $perPage): array
    {
        // The consent contracts are not offered: see ProtectedKeys.
        $where = ['absent_at IS NULL', 'message_key NOT IN (:protected)'];
        $params = ['protected' => ProtectedKeys::KEYS];
        $types = ['protected' => ArrayParameterType::STRING];

        $q = trim($q);
        if ('' !== $q) {
            // ILIKE wildcards escaped in the bound value, never concatenated into SQL.
            $where[] = '(message_key ILIKE :q OR english ILIKE :q)';
            $params['q'] = '%'.$this->escapeIlike($q).'%';
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM translation_entry WHERE {$whereSql}",
            $params,
            $types,
        );

        $pager = Pager::of($page, $total, $perPage);

        /** @var list<array{id: int|string, message_key: string, english: string}> $raw */
        $raw = $this->db->fetchAllAssociative(
            "SELECT id, message_key, english
             FROM translation_entry
             WHERE {$whereSql}
             ORDER BY message_key ASC
             LIMIT {$pager['perPage']} OFFSET {$pager['offset']}",
            $params,
            $types,
        );

        $overlayMap = $this->overlays->map($locale);
        $entryIds = array_map(static fn (array $r): int => (int) $r['id'], $raw);
        $pendingIds = $this->pendingEntryIds($locale, $entryIds);

        $rows = [];
        foreach ($raw as $row) {
            $key = $row['message_key'];
            $yamlDefault = $this->yamlTranslator->trans($key, [], 'messages', $locale);
            $id = (int) $row['id'];
            $rows[] = [
                'id' => $id,
                'message_key' => $key,
                'english' => $row['english'],
                'live' => $overlayMap[$key] ?? $yamlDefault,
                'has_pending' => isset($pendingIds[$id]),
            ];
        }

        return ['rows' => $rows, 'pager' => $pager];
    }

    /**
     * @return array{yaml_default: string, live: string}
     */
    public function liveFor(TranslationEntry $entry, string $locale): array
    {
        $key = $entry->getMessageKey();
        $yamlDefault = $this->yamlTranslator->trans($key, [], 'messages', $locale);
        $overlayMap = $this->overlays->map($locale);

        return [
            'yaml_default' => $yamlDefault,
            'live' => $overlayMap[$key] ?? $yamlDefault,
        ];
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
