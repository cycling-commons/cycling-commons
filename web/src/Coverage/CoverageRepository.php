<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Coverage;

use App\Catalog\CoverageRetirement;
use App\Catalog\Entity\Item;
use App\Catalog\ItemState;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Read plane over the pipeline-owned coverage_poi cache
 * (coverage-provider.md §5): drawer detail, name search,
 * town-card nearby and rail counts for the uncurated OSM tier. Raw DBAL like
 * App\Catalog\CatalogProvider — the map read path never hydrates entities.
 *
 * Dedupe rule (osm-data-architecture.md §8): a coverage row is suppressed
 * wherever a *payload-served* `item` with the same source_ref exists — the
 * object appears once, as curated. "Payload-served" mirrors
 * CatalogProvider::itemRows()/curatedRefs() exactly (coverage-provider.md
 * §6/§9): a served item row that also matches the coverage-retirement
 * predicate (App\Catalog\CoverageRetirement::untouchedOsmSql()) is *not*
 * payload-served — its tile twin renders as community, so search/nearby/
 * counts must key "curated" the same way, or the query plane and the tiles
 * disagree and the owner-gated retirement DELETE would visibly change
 * search/nearby (coverage-provider.md §9's "zero display change").
 *
 * @api Consumed by CoverageController.
 */
final class CoverageRepository
{
    /** The serving-cache attribution line (osm-data-architecture.md §4). */
    public const string ATTRIBUTION = '© OpenStreetMap contributors (ODbL)';

    /**
     * Display whitelist for cached OSM tags (coverage-provider.md §5): store
     * rich, serve trimmed — the drawer never sees the full filtered tag set.
     */
    public const array TAG_WHITELIST = [
        'opening_hours', 'website', 'contact:website', 'url', 'phone', 'contact:phone',
        'addr:city', 'addr:street', 'addr:housenumber', 'operator', 'description',
        'wheelchair', 'drinking_water', 'fee', 'capacity',
    ];

    /**
     * Coverage POI letters (osm-data-architecture.md §5; A/B/K stay
     * curated-only).
     */
    private const string POI_LETTERS_SQL = "('C', 'D', 'E', 'G', 'H', 'I', 'J')";

    /** Community items listed per nearby letter group before the "show all" expander (coverage-provider.md §5). */
    private const int NEARBY_COMMUNITY_CAP = 3;

    /** Default result cap for search() (coverage-provider.md §5). */
    public const int SEARCH_LIMIT = 12;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Drawer payload for one coverage POI, curated overlay merged
     * (coverage-provider.md §5 keys: ref, letter, name, kind, ll, tags,
     * curated, attribution). Null
     * when the ref is not in the coverage cache.
     *
     * @return array<string, mixed>|null
     */
    public function detail(string $osmType, int $osmId): ?array
    {
        $ref = $osmType.'/'.$osmId;
        /** @var array{ref: string, letter: string, kind: string|null, name: string|null, lat: string|float, lng: string|float, tags: string, item_id: int|string|null, item_state: string|null, item_name: string|null, item_attributes: string|null}|false $row */
        $row = $this->db->fetchAssociative(
            'SELECT cp.ref, cp.letter, cp.kind, cp.name,
                    ST_Y(cp.geom) AS lat, ST_X(cp.geom) AS lng, cp.tags,
                    i.id AS item_id, i.state AS item_state, i.name AS item_name, i.attributes AS item_attributes
             FROM coverage_poi cp
             LEFT JOIN item i ON i.source_ref = cp.ref AND i.state IN '.ItemState::servedSqlTuple().'
             WHERE cp.ref = :ref
             ORDER BY cp.letter, i.id
             LIMIT 1',
            ['ref' => $ref],
        );
        if (false === $row) {
            return null;
        }

        /** @var array<string, mixed> $tags */
        $tags = json_decode($row['tags'], true, 512, \JSON_THROW_ON_ERROR);

        return [
            'ref' => $row['ref'],
            'letter' => $row['letter'],
            'name' => $row['name'],
            'kind' => $row['kind'],
            'll' => [(float) $row['lat'], (float) $row['lng']],
            // (object) so an empty whitelist intersection still encodes {}.
            'tags' => (object) array_intersect_key($tags, array_flip(self::TAG_WHITELIST)),
            'curated' => null === $row['item_id']
                ? null
                : $this->curatedOverlay((int) $row['item_id'], (string) $row['item_state'], (string) $row['item_name'], (string) $row['item_attributes']),
            'attribution' => self::ATTRIBUTION,
        ];
    }

    /**
     * Ranked name search, curated first (coverage-provider.md §5): payload-served items
     * matched by name, then coverage rows not shadowed by a payload-served
     * ref — both pg_trgm-ranked, deduped by ref (osm-data-architecture.md
     * §8). "Payload-served" excludes the coverage-retirement predicate
     * (CoverageRetirement::untouchedOsmSql()) so an untouched legacy row
     * lists as community, matching its tile twin.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $q, int $limit = self::SEARCH_LIMIT): array
    {
        $like = '%'.addcslashes($q, '\\%_').'%';
        /** @var list<array{item_id: int|string, ref: string, letter: string, name: string, kind: string|null, lat: string|float, lng: string|float}> $curated */
        $curated = $this->db->fetchAllAssociative(
            "SELECT i.id AS item_id, i.source_ref AS ref, i.letter, i.name,
                    i.attributes->>'serviceKind' AS kind,
                    ST_Y(i.geom) AS lat, ST_X(i.geom) AS lng
             FROM item i
             WHERE i.letter IN ".self::POI_LETTERS_SQL.'
               AND i.state IN '.ItemState::servedSqlTuple().'
               AND i.name ILIKE :like
               AND NOT ('.CoverageRetirement::untouchedOsmSql('i').')
             ORDER BY similarity(i.name, :q) DESC, i.id
             LIMIT :limit',
            ['like' => $like, 'q' => $q, 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        $results = [];
        foreach ($curated as $row) {
            $results[] = $this->entry($row, curated: true, itemId: (int) $row['item_id']);
        }

        $remaining = $limit - \count($results);
        if ($remaining > 0) {
            /** @var list<array{ref: string, letter: string, name: string|null, kind: string|null, lat: string|float, lng: string|float}> $coverage */
            $coverage = $this->db->fetchAllAssociative(
                'SELECT cp.ref, cp.letter, cp.name, cp.kind, ST_Y(cp.geom) AS lat, ST_X(cp.geom) AS lng
                 FROM coverage_poi cp
                 WHERE cp.name ILIKE :like
                   AND NOT EXISTS (SELECT 1 FROM item i WHERE i.source_ref = cp.ref AND i.state IN '.ItemState::servedSqlTuple().' AND NOT ('.CoverageRetirement::untouchedOsmSql('i').'))
                 ORDER BY similarity(cp.name, :q) DESC, cp.id
                 LIMIT :limit',
                ['like' => $like, 'q' => $q, 'limit' => $remaining],
                ['limit' => ParameterType::INTEGER],
            );
            foreach ($coverage as $row) {
                $results[] = $this->entry($row, curated: false);
            }
        }

        return $results;
    }

    /**
     * Letter-grouped POIs within $km of a point (the town card, coverage-provider.md §5):
     * payload-served entries first, then the nearest NEARBY_COMMUNITY_CAP
     * community rows; `total` counts everything in range so the client can
     * render the "show all" expander (07-15 decision A). "Payload-served"
     * excludes the coverage-retirement predicate
     * (CoverageRetirement::untouchedOsmSql()) so an untouched legacy row
     * lists as community, matching its tile twin.
     *
     * @return list<array{letter: string, total: int, items: list<array<string, mixed>>}>
     */
    public function nearby(float $lat, float $lng, float $km): array
    {
        $params = ['lat' => $lat, 'lng' => $lng, 'm' => $km * 1000.0];
        $point = 'ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography';

        /** @var list<array{item_id: int|string, ref: string, letter: string, name: string, kind: string|null, lat: string|float, lng: string|float}> $curated */
        $curated = $this->db->fetchAllAssociative(
            "SELECT i.id AS item_id, i.source_ref AS ref, i.letter, i.name,
                    i.attributes->>'serviceKind' AS kind,
                    ST_Y(i.geom) AS lat, ST_X(i.geom) AS lng
             FROM item i
             WHERE i.letter IN ".self::POI_LETTERS_SQL.'
               AND i.state IN '.ItemState::servedSqlTuple()."
               AND ST_DWithin(i.geom::geography, $point, :m)
               AND NOT (".CoverageRetirement::untouchedOsmSql('i').")
             ORDER BY i.letter, ST_Distance(i.geom::geography, $point), i.id",
            $params,
        );

        /** @var list<array{letter: string, ref: string, name: string|null, kind: string|null, lat: string|float, lng: string|float, letter_total: int|string}> $community */
        $community = $this->db->fetchAllAssociative(
            "SELECT letter, ref, name, kind, lat, lng, letter_total FROM (
                 SELECT cp.letter, cp.ref, cp.name, cp.kind,
                        ST_Y(cp.geom) AS lat, ST_X(cp.geom) AS lng,
                        ROW_NUMBER() OVER (PARTITION BY cp.letter ORDER BY ST_Distance(cp.geom::geography, $point), cp.id) AS rn,
                        COUNT(*) OVER (PARTITION BY cp.letter) AS letter_total
                 FROM coverage_poi cp
                 WHERE ST_DWithin(cp.geom::geography, $point, :m)
                   AND NOT EXISTS (SELECT 1 FROM item i WHERE i.source_ref = cp.ref AND i.state IN ".ItemState::servedSqlTuple().' AND NOT ('.CoverageRetirement::untouchedOsmSql('i').'))
             ) ranked
             WHERE rn <= '.self::NEARBY_COMMUNITY_CAP.'
             ORDER BY letter, rn',
            $params,
        );

        $groups = [];
        foreach ($curated as $row) {
            $groups[$row['letter']]['items'][] = $this->entry($row, curated: true, itemId: (int) $row['item_id']);
            $groups[$row['letter']]['curated_total'] = ($groups[$row['letter']]['curated_total'] ?? 0) + 1;
        }
        foreach ($community as $row) {
            $groups[$row['letter']]['items'][] = $this->entry($row, curated: false);
            // letter_total counts every community row in range, capped rows included.
            $groups[$row['letter']]['community_total'] = (int) $row['letter_total'];
        }

        $out = [];
        foreach (str_split('CDEGHIJ') as $letter) {
            if (!isset($groups[$letter])) {
                continue;
            }
            $out[] = [
                'letter' => $letter,
                'total' => ($groups[$letter]['curated_total'] ?? 0) + ($groups[$letter]['community_total'] ?? 0),
                'items' => $groups[$letter]['items'],
            ];
        }

        return $out;
    }

    /**
     * Per-letter coverage totals for the rail (coverage-provider.md §5). The
     * letter filter is defensive: the {C..J} response shape is
     * code-guaranteed, never dependent on what the pipeline loaded. Rows
     * shadowed by a payload-served item (same weakened-shadow semantics as
     * search()/nearby()) are excluded, so the rail total stays coherent with
     * the map, which adds payload features on top of the tile layer — a
     * confirmed item's coverage twin must not count twice.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        /** @var list<array{letter: string, n: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT cp.letter, COUNT(*) AS n FROM coverage_poi cp
             WHERE cp.letter IN '.self::POI_LETTERS_SQL.'
               AND NOT EXISTS (SELECT 1 FROM item i WHERE i.source_ref = cp.ref AND i.state IN '.ItemState::servedSqlTuple().' AND NOT ('.CoverageRetirement::untouchedOsmSql('i').'))
             GROUP BY cp.letter ORDER BY cp.letter',
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['letter']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * One search/nearby entry: {ref, letter, n?, kind?, ll, curated, itemId?}.
     *
     * @param array{ref: string, letter: string, name: string|null, kind: string|null, lat: string|float, lng: string|float, ...<array-key, mixed>} $row
     *
     * @return array<string, mixed>
     */
    private function entry(array $row, bool $curated, ?int $itemId = null): array
    {
        $entry = ['ref' => $row['ref'], 'letter' => $row['letter']];
        if (null !== $row['name'] && '' !== $row['name']) {
            $entry['n'] = $row['name'];
        }
        if (null !== $row['kind']) {
            $entry['kind'] = $row['kind'];
        }
        $entry['ll'] = [(float) $row['lat'], (float) $row['lng']];
        $entry['curated'] = $curated;
        if (null !== $itemId) {
            $entry['itemId'] = $itemId;
        }

        return $entry;
    }

    /**
     * The curated overlay block: canonical fields (attributes + the name
     * pseudo-field) and public confirmation tallies — the same GROUP BY as
     * ItemConfirmationService::snapshot().
     *
     * @return array{itemId: int, state: string, fields: object, confirmations: object}
     */
    private function curatedOverlay(int $itemId, string $state, string $name, string $attributesJson): array
    {
        /** @var array<string, mixed> $fields */
        $fields = json_decode($attributesJson, true, 512, \JSON_THROW_ON_ERROR);
        if ('' !== $name) {
            $fields[Item::NAME_FIELD] = $name;
        }

        /** @var list<array{stance: string, n: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT stance, COUNT(*) AS n FROM item_confirmation WHERE item_id = :id GROUP BY stance ORDER BY stance',
            ['id' => $itemId],
            ['id' => ParameterType::INTEGER],
        );
        $tallies = [];
        foreach ($rows as $row) {
            $tallies[$row['stance']] = (int) $row['n'];
        }

        return [
            'itemId' => $itemId,
            'state' => $state,
            'fields' => (object) $fields,
            'confirmations' => (object) $tallies,
        ];
    }
}
