<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Coverage;

use App\Catalog\CoverageRetirement;
use App\Catalog\Entity\Item;
use App\Catalog\ItemState;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Read plane over the coverage_poi cache filled by the coverage pipeline:
 * drawer detail, name search, town-card nearby lists and rail counts for
 * the uncurated OSM tier. Uses raw DBAL, like App\Catalog\CatalogProvider,
 * because the map read path never hydrates entities.
 *
 * A coverage row is hidden whenever a payload-served `item` shares its
 * source_ref, so each place appears once, as curated, and the results
 * match what the map tiles show. "Payload-served" excludes any item row
 * that also matches the coverage-retirement predicate, so a legacy row
 * that is about to be retired still counts as community here too.
 *
 * The NOT EXISTS checks below test "not touched" without a letter filter,
 * unlike detail()/curatedRefs(). This only matters if a single OSM element
 * were somehow both an untouched surface item and a POI at the same time,
 * which should not happen in practice.
 *
 * @see docs/specs/coverage-provider.md §5
 * @see docs/specs/osm-data-architecture.md §8
 *
 * @api Consumed by CoverageController.
 */
final class CoverageRepository
{
    /** The serving-cache attribution line (osm-data-architecture.md §4). */
    public const string ATTRIBUTION = '© OpenStreetMap contributors (ODbL)';

    /**
     * Tags shown to the public for a coverage POI. The cache stores every
     * OSM tag, but only these are ever sent to the drawer.
     *
     * @see docs/specs/coverage-provider.md §5
     */
    public const array TAG_WHITELIST = [
        'opening_hours', 'website', 'contact:website', 'url', 'phone', 'contact:phone',
        'addr:city', 'addr:street', 'addr:housenumber', 'operator', 'description',
        'wheelchair', 'drinking_water', 'fee', 'capacity',
    ];

    /**
     * Coverage POI letters. A, B and K stay curated-only.
     *
     * @see docs/specs/osm-data-architecture.md §5
     */
    private const string POI_LETTERS_SQL = "('C', 'D', 'E', 'G', 'H', 'I', 'J', 'M')";

    /** Community items listed per nearby letter group before the "show all" expander. */
    private const int NEARBY_COMMUNITY_CAP = 3;

    /** Default result cap for search(). */
    public const int SEARCH_LIMIT = 12;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * SQL filter arm for the active region scope (region-scoping-design.md §6),
     * or '' for the Everywhere scope (no params). `rids` (region ids) compiles
     * to `<alias>.region_id IN (:rids)`; `cc` to `<alias>.country_code = :cc`;
     * a country scope sends both, ORed, so a stamped-region row OR an unsplit
     * country row (region_id NULL, cc set) both pass. Placeholders are bound
     * once per query via scopeBind(). Absent params = current behaviour,
     * backward compatible.
     *
     * @param list<int> $rids
     */
    private function scopeArm(string $alias, array $rids, ?string $cc): string
    {
        $arms = [];
        if ([] !== $rids) {
            $arms[] = "$alias.region_id IN (:rids)";
        }
        if (null !== $cc) {
            $arms[] = "$alias.country_code = :cc";
        }

        return [] === $arms ? '' : ' AND ('.implode(' OR ', $arms).')';
    }

    /**
     * Bind the :rids/:cc placeholders scopeArm() references onto a query's
     * param + type maps. A no-op arm adds nothing, so an Everywhere scope
     * leaves the query untouched.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     * @param list<int>            $rids
     */
    private function scopeBind(array &$params, array &$types, array $rids, ?string $cc): void
    {
        if ([] !== $rids) {
            $params['rids'] = $rids;
            $types['rids'] = ArrayParameterType::INTEGER;
        }
        if (null !== $cc) {
            $params['cc'] = $cc;
        }
    }

    /**
     * Drawer payload for one coverage POI, with the curated overlay merged
     * in when the place is also a payload-served item. Returns null when
     * the ref is not in the coverage cache.
     *
     * The join that decides "curated" must match CatalogProvider::
     * curatedRefs() exactly, so an untouched legacy row (rendered as
     * community on the tiles) never shows a curated block in the drawer.
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
                 AND NOT (i.letter IN '.CoverageRetirement::lettersSqlTuple().' AND '.CoverageRetirement::untouchedOsmSql('i').')
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
     * Ranked name search, curated first: payload-served items matched by
     * name, then coverage rows not already covered by one of those items.
     * Both lists are ranked by trigram similarity and deduped by ref.
     *
     * "Payload-served" excludes the coverage-retirement predicate, so an
     * untouched legacy row lists as community, matching its tile.
     *
     * `rids`/`cc` scope the results to the active region scope
     * (region-scoping-design.md §6), so the sidebar mirrors the scope-filtered
     * tiles; absent = every row (backward compatible).
     *
     * @see docs/specs/coverage-provider.md §5
     * @see docs/specs/osm-data-architecture.md §8
     *
     * @param list<int> $rids
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $q, array $rids = [], ?string $cc = null, int $limit = self::SEARCH_LIMIT): array
    {
        $like = '%'.addcslashes($q, '\\%_').'%';
        $curatedParams = ['like' => $like, 'q' => $q, 'limit' => $limit];
        $curatedTypes = ['limit' => ParameterType::INTEGER];
        // Curated (served-item) arm is rid-ONLY, deliberately dropping cc: the
        // map's served-data gate (map.js inScope) is rid-only and the catalog
        // payload carries no cc, so an item admitted by a cc arm here but hidden
        // by rid there would list a POI whose pin the map hides. A served item
        // gets its region stamped on write (SpatialResolver) or by the importer's
        // membership recompute, so rid-only is complete once membership runs.
        // The community/coverage arm below keeps cc (the tile filter admits
        // cc-scoped rows), so the two tiers scope by their own authoritative key.
        $this->scopeBind($curatedParams, $curatedTypes, $rids, null);
        /** @var list<array{item_id: int|string, ref: string, letter: string, name: string, kind: string|null, lat: string|float, lng: string|float}> $curated */
        $curated = $this->db->fetchAllAssociative(
            "SELECT i.id AS item_id, i.source_ref AS ref, i.letter, i.name,
                    i.attributes->>'serviceKind' AS kind,
                    ST_Y(i.geom) AS lat, ST_X(i.geom) AS lng
             FROM item i
             WHERE i.letter IN ".self::POI_LETTERS_SQL.'
               AND i.state IN '.ItemState::servedSqlTuple().'
               AND i.name ILIKE :like
               AND NOT ('.CoverageRetirement::untouchedOsmSql('i').')'
               .$this->scopeArm('i', $rids, null).'
             ORDER BY similarity(i.name, :q) DESC, i.id
             LIMIT :limit',
            $curatedParams,
            $curatedTypes,
        );

        $results = [];
        foreach ($curated as $row) {
            $results[] = $this->entry($row, curated: true, itemId: (int) $row['item_id']);
        }

        $remaining = $limit - \count($results);
        if ($remaining > 0) {
            $covParams = ['like' => $like, 'q' => $q, 'limit' => $remaining];
            $covTypes = ['limit' => ParameterType::INTEGER];
            $this->scopeBind($covParams, $covTypes, $rids, $cc);
            /** @var list<array{ref: string, letter: string, name: string|null, kind: string|null, lat: string|float, lng: string|float}> $coverage */
            $coverage = $this->db->fetchAllAssociative(
                'SELECT cp.ref, cp.letter, cp.name, cp.kind, ST_Y(cp.geom) AS lat, ST_X(cp.geom) AS lng
                 FROM coverage_poi cp
                 WHERE cp.name ILIKE :like
                   AND NOT EXISTS (SELECT 1 FROM item i WHERE i.source_ref = cp.ref AND i.state IN '.ItemState::servedSqlTuple().' AND NOT ('.CoverageRetirement::untouchedOsmSql('i').'))'
                   .$this->scopeArm('cp', $rids, $cc).'
                 ORDER BY similarity(cp.name, :q) DESC, cp.id
                 LIMIT :limit',
                $covParams,
                $covTypes,
            );
            foreach ($coverage as $row) {
                $results[] = $this->entry($row, curated: false);
            }
        }

        return $results;
    }

    /**
     * Letter-grouped POIs within $km of a point, for the town card:
     * payload-served entries first, then the nearest NEARBY_COMMUNITY_CAP
     * community rows. `total` counts everything in range, so the client can
     * render a "show all" expander.
     *
     * "Payload-served" excludes the coverage-retirement predicate, so an
     * untouched legacy row lists as community, matching its tile.
     *
     * `rids`/`cc` scope the groups to the active region scope
     * (region-scoping-design.md §6); absent = every row.
     *
     * @see docs/specs/coverage-provider.md §5
     *
     * @param list<int> $rids
     *
     * @return list<array{letter: string, total: int, items: list<array<string, mixed>>}>
     */
    public function nearby(float $lat, float $lng, float $km, array $rids = [], ?string $cc = null): array
    {
        // Curated arm is rid-only (see search()): its own param set so :cc is
        // never bound for a query that no longer references it.
        $curatedParams = ['lat' => $lat, 'lng' => $lng, 'm' => $km * 1000.0];
        $curatedTypes = [];
        $this->scopeBind($curatedParams, $curatedTypes, $rids, null);
        $params = ['lat' => $lat, 'lng' => $lng, 'm' => $km * 1000.0];
        $types = [];
        $this->scopeBind($params, $types, $rids, $cc);
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
               AND NOT (".CoverageRetirement::untouchedOsmSql('i').')'
               .$this->scopeArm('i', $rids, null)."
             ORDER BY i.letter, ST_Distance(i.geom::geography, $point), i.id",
            $curatedParams,
            $curatedTypes,
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
                   AND NOT EXISTS (SELECT 1 FROM item i WHERE i.source_ref = cp.ref AND i.state IN ".ItemState::servedSqlTuple().' AND NOT ('.CoverageRetirement::untouchedOsmSql('i').'))'
                   .$this->scopeArm('cp', $rids, $cc).'
             ) ranked
             WHERE rn <= '.self::NEARBY_COMMUNITY_CAP.'
             ORDER BY letter, rn',
            $params,
            $types,
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
        foreach (str_split('CDEGHIJM') as $letter) {
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
     * Per-letter coverage totals for the rail. The letter filter is
     * defensive, so the {C..J} response shape never depends on what the
     * pipeline actually loaded.
     *
     * Rows already covered by a payload-served item are excluded (the same
     * rule as search()/nearby()), so a confirmed item's coverage twin is
     * never counted twice.
     *
     * `rids`/`cc` make the totals scope-aware (region-scoping-design.md §6/§7):
     * the rail badge's "total" side then matches the scope-filtered "shown"
     * dots the client renders from the tile props; absent = global totals.
     *
     * @see docs/specs/coverage-provider.md §5
     *
     * @param list<int> $rids
     *
     * @return array<string, int>
     */
    public function counts(array $rids = [], ?string $cc = null): array
    {
        $params = [];
        $types = [];
        $this->scopeBind($params, $types, $rids, $cc);
        /** @var list<array{letter: string, n: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT cp.letter, COUNT(*) AS n FROM coverage_poi cp
             WHERE cp.letter IN '.self::POI_LETTERS_SQL.'
               AND NOT EXISTS (SELECT 1 FROM item i WHERE i.source_ref = cp.ref AND i.state IN '.ItemState::servedSqlTuple().' AND NOT ('.CoverageRetirement::untouchedOsmSql('i').'))'
               .$this->scopeArm('cp', $rids, $cc).'
             GROUP BY cp.letter ORDER BY cp.letter',
            $params,
            $types,
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
     * The curated overlay block: canonical fields (attributes plus the name
     * pseudo-field) and public confirmation tallies. Uses the same GROUP BY
     * as ItemConfirmationService::snapshot().
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
