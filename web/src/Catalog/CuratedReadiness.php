<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * How much curated best-of content a region actually has, **per block**, and
 * therefore whether a moderator may flip it to open in Curated mode
 * (map-and-search.md §4.2; owner decision "B", the GATED
 * option: the flag cannot be set prematurely).
 *
 * The count deliberately mirrors what Curated mode would SHOW, because that is
 * the thing a rider judges the region by:
 *
 *  - items on the experiential layers (`render.js` featureVisible: A surface,
 *    B climbs, E stays, I scenic, J history) that carry the curated flag —
 *    in Curated mode a non-curated item on those layers is hidden outright;
 *  - plus the region's best-of routes (K), i.e. verified `recommended_route`
 *    rows with at least one vote (the same set `RouteRankingService::bestOf`
 *    ranks).
 *
 * Utility layers (C water, D services, F hazards, G transit, H shelter) are
 * excluded on purpose: they render in BOTH modes, so they cannot be evidence
 * that Curated has anything to show. That is precisely the trap the owner hit —
 * a full utility map behind a rail reading "0 places shown".
 *
 * ## Why a total alone is not enough (owner question, 2026-07-27)
 *
 * A flat "25 curated items" can be met by **25 scenic views and nothing else**,
 * and a rider who opens that region in Curated then finds nowhere to sleep, no
 * climbs and no routes — the same empty-feeling map the default was flipped to
 * avoid, just with a different hole in it. So readiness is **breadth AND
 * depth**: a total, plus a minimum number of BLOCKS that each carry a minimum
 * of their own. No single layer can carry a region.
 *
 * Breadth is expressed as "N blocks of M" rather than "every block", because
 * regions legitimately differ: a Dutch province has no climbs and never will,
 * and must still be able to qualify on stays + scenic + history + routes.
 *
 * All three numbers are config (README threshold principle) and are editable at
 * runtime from the admin system-config page (system-configuration.md §2), so
 * they are read through SettingsProviderInterface rather than bound as
 * constructor scalars — tuning the gate must not need a deploy. Setting
 * `minBlocks` to 1 reverts the gate to a pure total.
 *
 * @api Consumed by the curator Regions desk and its gated toggle.
 */
final class CuratedReadiness
{
    /**
     * The blocks a region's readiness is measured across, in map order.
     * Letters A/B/E/I/J are the layers Curated HIDES when an item is not curated
     * (`exp: true` in `web/assets/map/catalog.js`); 'K' is the best-of route set,
     * which is counted from `recommended_route`, not `item`. Keep in step with
     * that list.
     *
     * @var array<string, string> letter => the item_type translation key
     */
    public const array BLOCKS = [
        'A' => 'item_type.road-surface.label',
        'B' => 'item_type.climbs.label',
        'E' => 'item_type.where-to-sleep.label',
        'I' => 'item_type.scenic-views.label',
        'J' => 'item_type.history-culture.label',
        'K' => 'item_type.quality-rides.label',
    ];

    /** The letters counted from the `item` table (everything but the K routes). */
    public const array ITEM_LETTERS = ['A', 'B', 'E', 'I', 'J'];

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
     * How many places in each region somebody has vouched for.
     *
     * The same test the map's Confirmed mode draws and the drawer's badge reads
     * (`CatalogProvider`: verified state, PIVOT provenance, or any confirmation
     * that is not the submitter's own form answer) — one definition, so the desk
     * cannot promise a mode that then shows something else.
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
                AND (i.state = 'verified' OR i.source = 'pivot'
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
     * Batch form, so the desk lists N regions in two queries rather than 2N.
     * Every requested region is present in the result, with a zero for every
     * block — the desk renders "0" per block, never a blank.
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
            // jsonb_exists(), NOT the `?` operator: DBAL parses `?` as a
            // positional parameter placeholder, so `attributes ? 'cur'` fails
            // the whole statement with "Positional parameter at index 0 does
            // not have a bound value". Same predicate, no ambiguity.
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

        // Best-of routes: verified AND voted, the same gate RouteRankingService
        // applies. A verified route nobody has voted for is not best-of yet, so
        // it must not count towards readiness.
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
            $counts[(int) $r['region_id']]['K'] = (int) $r['n'];
        }

        // Read once per batch, not once per region: the values are settings now,
        // and every region in one report must be judged by the same numbers.
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
