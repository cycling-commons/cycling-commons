<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Per-block curated content a region has, and whether Curated-by-default may unlock. Utility layers do not count — they render in both modes.
 *
 * @see docs/specs/map-and-search.md §4.2
 *
 * @api
 */
final class CuratedReadiness
{
    /**
     * Experiential layers Curated hides when an item is not curated, plus R from `recommended_route`.
     *
     * @var array<string, string> letter => the item_type translation key
     */
    public const array BLOCKS = [
        'A' => 'item_type.road-surface.label',
        'N' => 'item_type.climbs.label',
        'O' => 'item_type.where-to-sleep.label',
        'P' => 'item_type.scenic-views.label',
        'Q' => 'item_type.history-culture.label',
        'R' => 'item_type.quality-rides.label',
    ];

    /** The letters counted from the `item` table (everything but the R routes). */
    public const array ITEM_LETTERS = ['A', 'N', 'O', 'P', 'Q'];

    public function __construct(
        private readonly Connection $db,
        private readonly SettingsProviderInterface $settings,
    ) {
    }

    /** The advisory total a region must reach before Curated-by-default unlocks. */
    public function threshold(): int
    {
        return $this->settings->get(SettingsRegistry::MAP_CURATED_THRESHOLD);
    }

    /** How many blocks must each carry at least minPerBlock(). */
    public function minBlocks(): int
    {
        return $this->settings->get(SettingsRegistry::MAP_CURATED_MIN_BLOCKS);
    }

    /** What a block must have to count towards the breadth requirement. */
    public function minPerBlock(): int
    {
        return $this->settings->get(SettingsRegistry::MAP_CURATED_MIN_PER_BLOCK);
    }

    public function isReady(int $regionId): bool
    {
        return $this->reportFor($regionId)['ready'];
    }

    /** How many confirmed places a region needs before it may OPEN in Confirmed. */
    public function confirmedThreshold(): int
    {
        return $this->settings->get(SettingsRegistry::MAP_CONFIRMED_THRESHOLD);
    }

    /**
     * Confirmed-mode count: same derivation as CatalogProvider (excludes `form` confirmations).
     *
     * @param list<int> $regionIds
     *
     * @return array<int, int> region id → count, every requested region present
     */
    public function confirmedCounts(array $regionIds): array
    {
        $counts = array_fill_keys($regionIds, 0);
        if ([] === $regionIds) {
            return $counts;
        }

        /** @var list<array{region_id: int, n: int}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT i.region_id, COUNT(*) AS n
               FROM item i
              WHERE i.region_id IN (:ids)
                AND i.state IN '.ItemState::servedSqlTuple()."
                AND (i.state = 'verified' OR i.source = 'authority'
                     OR EXISTS (SELECT 1 FROM item_confirmation c
                                 WHERE c.item_id = i.id AND c.source <> 'form'))
              GROUP BY i.region_id",
            ['ids' => $regionIds],
            ['ids' => ArrayParameterType::INTEGER],
        );
        foreach ($rows as $row) {
            $counts[(int) $row['region_id']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * The full picture for one region: per-block counts, the total, how far off
     * each requirement is, and the verdict.
     *
     * @return array{blocks: array<string, int>, total: int, blocksMet: int, shortTotal: int, shortBlocks: int, ready: bool}
     */
    public function reportFor(int $regionId): array
    {
        return $this->reportForRegions([$regionId])[$regionId];
    }

    /**
     * Batch form. Every requested region is present, zeros included.
     *
     * @param list<int> $regionIds
     *
     * @return array<int, array{blocks: array<string, int>, total: int, blocksMet: int, shortTotal: int, shortBlocks: int, ready: bool}>
     */
    public function reportForRegions(array $regionIds): array
    {
        if ([] === $regionIds) {
            return [];
        }
        $zero = array_fill_keys(array_keys(self::BLOCKS), 0);
        /** @var array<int, array<string, int>> $counts */
        $counts = array_fill_keys($regionIds, $zero);

        /** @var list<array{region_id: int|string, letter: string, n: int|string}> $items */
        $items = $this->db->fetchAllAssociative(
            // jsonb_exists(), not `?` — DBAL treats `?` as a placeholder.
            "SELECT region_id, letter, COUNT(*) AS n
               FROM item
              WHERE region_id IN (:rids)
                AND letter IN (:letters)
                AND jsonb_exists(attributes, 'cur')
                AND attributes ->> 'cur' NOT IN ('false', '0', '')
              GROUP BY region_id, letter",
            ['rids' => $regionIds, 'letters' => self::ITEM_LETTERS],
            ['rids' => ArrayParameterType::INTEGER, 'letters' => ArrayParameterType::STRING],
        );
        foreach ($items as $r) {
            $counts[(int) $r['region_id']][(string) $r['letter']] = (int) $r['n'];
        }

        // Verified AND voted — same gate as RouteRankingService.
        /** @var list<array{region_id: int|string, n: int|string}> $routes */
        $routes = $this->db->fetchAllAssociative(
            "SELECT rr.region_id, COUNT(DISTINCT rr.id) AS n
               FROM recommended_route rr
               JOIN route_vote rv ON rv.route_id = rr.id
              WHERE rr.region_id IN (:rids)
                AND rr.state = 'verified'
              GROUP BY rr.region_id",
            ['rids' => $regionIds],
            ['rids' => ArrayParameterType::INTEGER],
        );
        foreach ($routes as $r) {
            $counts[(int) $r['region_id']]['R'] = (int) $r['n'];
        }

        // Read once per batch so every region is judged by the same settings.
        $threshold = $this->threshold();
        $minBlocks = $this->minBlocks();
        $minPerBlock = $this->minPerBlock();

        $out = [];
        foreach ($counts as $rid => $blocks) {
            $total = array_sum($blocks);
            $blocksMet = \count(array_filter($blocks, fn (int $n): bool => $n >= $minPerBlock));
            $out[$rid] = [
                'blocks' => $blocks,
                'total' => $total,
                'blocksMet' => $blocksMet,
                'shortTotal' => max(0, $threshold - $total),
                'shortBlocks' => max(0, $minBlocks - $blocksMet),
                'ready' => $total >= $threshold && $blocksMet >= $minBlocks,
            ];
        }

        return $out;
    }
}
