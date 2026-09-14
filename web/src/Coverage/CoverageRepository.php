<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Coverage;

use App\Catalog\CoverageRetirement;
use App\Catalog\Entity\Item;
use App\Catalog\GoneRows;
use App\Catalog\ItemState;
use App\Catalog\ScenicPhotoRule;
use App\Media\Commons\CommonsFile;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Read plane over coverage_poi (docs/specs/coverage-provider.md §5).
 * A coverage row is hidden when a payload-served item IS that object, which is
 * `source_ref` for a harvested row and `osm_ref` for everything else.
 *
 * source_ref alone was not enough. It is the harvest's own upsert key, so a
 * Wallonia row carries `fx:pivot:hotel-koru|ramillies` and a Wikidata row
 * `wikidata:Q322824`; neither can ever equal `node/6123208864`. OSM is the
 * identity spine and `osm_ref` is the join key (catalog-data-model.md §5b,
 * osm-data-architecture.md §1), which is why the Item entity carries it at all.
 *
 * The visible symptom was that resolving a duplicate did not deduplicate.
 * Retiring the OSM twin of a Wikidata row unserved it, this join stopped
 * suppressing its coverage POI, and the raw OSM pin came back beside the row
 * the curator had just kept (owner, 2026-08-25). The moderation desk now hands
 * the survivor the retired row's osm_ref, and this is the half that makes that
 * mean something.
 *
 * @see docs/specs/coverage-provider.md §5
 * @see docs/specs/osm-data-architecture.md §8
 *
 * @api
 */
final class CoverageRepository
{
    /** The serving-cache attribution line (docs/specs/osm-data-architecture.md §4). */
    public const string ATTRIBUTION = '© OpenStreetMap contributors (ODbL)';

    /**
     * Tags sent to the drawer; the cache stores every OSM tag (docs/specs/coverage-provider.md §5).
     */
    public const array TAG_WHITELIST = [
        'opening_hours', 'website', 'contact:website', 'url', 'phone', 'contact:phone',
        'addr:city', 'addr:street', 'addr:housenumber', 'operator', 'description',
        'wheelchair', 'drinking_water', 'fee', 'capacity',
        // `amenity` is here for one reason: the water pin is drawn blue when
        // OSM says `drinking_water=yes` OR when the node is an
        // `amenity=drinking_water` with nothing said against it
        // (pipeline/coverage/tiles.py). Without this tag the drawer cannot
        // tell the second case from "nobody said anything", so the panel said
        // "unknown" beside a blue pin. The pin and the panel have to agree.
        'amenity', 'shop',
        // Scenic-view detail (letter P): a peak's altitude, which way a
        // viewpoint faces, and how far a waterfall drops. All three are plain
        // OSM tags the harvest now stores; the drawer reads them for P only,
        // but the whitelist is per-tag, not per-letter, so a peak reached
        // through any other letter shows the same fact rather than hiding it.
        'ele', 'direction', 'height',
        // What a photo of this place might be found under
        // (coverage-provider.md §7). Served as well as read here, because they
        // are the citation for a picture a rider is looking at.
        'image', 'wikimedia_commons', 'wikidata',
    ];

    /**
     * Coverage POI letters. A, N and R stay curated-only.
     *
     * @see docs/specs/osm-data-architecture.md §5
     */
    private const string POI_LETTERS_SQL = "('B', 'C', 'D', 'F', 'G', 'O', 'P', 'Q')";

    /** Community items listed per nearby letter group before the "show all" expander. */
    private const int NEARBY_COMMUNITY_CAP = 3;

    /** Default result cap for search(). */
    public const int SEARCH_LIMIT = 12;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Region-scope SQL arm, or '' for Everywhere (docs/specs/map-and-search.md §4.5).
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
     * Bind :rids/:cc from scopeArm(); no-op when Everywhere.
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
     * Drawer payload for one coverage POI. Curated join matches CatalogProvider::curatedRefs().
     *
     * @return array<string, mixed>|null
     */
    public function detail(string $osmType, int $osmId): ?array
    {
        $ref = $osmType.'/'.$osmId;
        /** @var array{ref: string, letter: string, kind: string|null, name: string|null, lat: string|float, lng: string|float, tags: string, item_id: int|string|null, item_state: string|null, item_name: string|null, item_attributes: string|null, item_letter: string|null, item_lat: string|float|null, item_lng: string|float|null}|false $row */
        $row = $this->db->fetchAssociative(
            'SELECT cp.ref, cp.letter, cp.kind, cp.name,
                    ST_Y(cp.geom) AS lat, ST_X(cp.geom) AS lng, cp.tags,
                    i.id AS item_id, i.state AS item_state, i.name AS item_name, i.attributes AS item_attributes,
                    i.letter AS item_letter, ST_Y(ST_PointOnSurface(i.geom)) AS item_lat, ST_X(ST_PointOnSurface(i.geom)) AS item_lng
             FROM coverage_poi cp
             LEFT JOIN item i ON cp.ref IN (i.source_ref, i.osm_ref) AND i.state IN '.ItemState::servedSqlTuple().'
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
            // coverage-provider.md §7 - only WHETHER a Commons file is
            // resolvable, never its state. This response is cached for 300s, so
            // a state would freeze at `pending` and the spinner would never
            // clear; the live state lives behind /map/coverage/photo, which is
            // no-store. The boolean also saves a second request for the 99.7%
            // of POIs that have no Commons file at all.
            'photo' => self::photoPossible($tags),
            'curated' => null === $row['item_id']
                ? null
                : $this->curatedOverlay(
                    (int) $row['item_id'], (string) $row['item_state'], (string) $row['item_name'], (string) $row['item_attributes'],
                    (string) $row['item_letter'],
                    is_numeric($row['item_lat']) ? (float) $row['item_lat'] : null,
                    is_numeric($row['item_lng']) ? (float) $row['item_lng'] : null,
                ),
            'attribution' => self::ATTRIBUTION,
        ];
    }

    /**
     * Whether a photo could exist for this POI, which is not the same as
     * whether one is ready.
     *
     * True for a resolvable Commons filename, and also for a bare Wikidata id,
     * because a third of those have a P18 (measured 2026-08-24) and that second
     * hop is where nearly all the photos come from. False here means the drawer
     * never even asks, so being conservative would silently hide 58,497 of the
     * rows most likely to have a picture.
     *
     * @param array<string, mixed> $tags
     */
    private static function photoPossible(array $tags): bool
    {
        if (null !== CommonsFile::fromTags($tags)) {
            return true;
        }
        $qid = $tags['wikidata'] ?? null;

        return \is_string($qid) && 1 === preg_match('~^Q\d+$~', $qid);
    }

    /**
     * Ranked name search, curated first (docs/specs/coverage-provider.md §5).
     * ILIKE wildcards are escaped. Curated arm is rid-only (see below).
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
        $like = '%'.addcslashes($q, '\\%_').'%'; // ILIKE wildcards escaped
        $curatedParams = ['like' => $like, 'q' => $q, 'limit' => $limit];
        $curatedTypes = ['limit' => ParameterType::INTEGER];
        // Curated arm is rid-only so a listed pin cannot be hidden by the map's rid gate (docs/specs/coverage-provider.md §5).
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
               AND NOT ('.CoverageRetirement::untouchedOsmSql('i').')
               AND '.GoneRows::notGoneSql('i')
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
                   AND NOT EXISTS (SELECT 1 FROM item i WHERE cp.ref IN (i.source_ref, i.osm_ref) AND i.state IN '.ItemState::servedSqlTuple().' AND NOT ('.CoverageRetirement::untouchedOsmSql('i').'))'
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
     * Letter-grouped POIs within $km (docs/specs/coverage-provider.md §5).
     *
     * @see docs/specs/coverage-provider.md §5
     *
     * @param list<int> $rids
     *
     * @return list<array{letter: string, total: int, items: list<array<string, mixed>>}>
     */
    public function nearby(float $lat, float $lng, float $km, array $rids = [], ?string $cc = null): array
    {
        // Curated arm is rid-only (see search()); :cc is not bound here.
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
               AND NOT (".CoverageRetirement::untouchedOsmSql('i').')
               AND '.GoneRows::notGoneSql('i')
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
                   AND NOT EXISTS (SELECT 1 FROM item i WHERE cp.ref IN (i.source_ref, i.osm_ref) AND i.state IN ".ItemState::servedSqlTuple().' AND NOT ('.CoverageRetirement::untouchedOsmSql('i').'))'
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
        foreach (str_split('BCDFGOPQ') as $letter) {
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
     * Per-letter coverage totals for the rail (docs/specs/coverage-provider.md §5).
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
        // A sum over coverage_count, the per (country, region, letter) table the
        // database keeps current by trigger (Version20260906180000), rather
        // than a walk over every coverage row: the unscoped walk took 26.6 s
        // on dev (owner, 2026-09-06, "Everywhere gets slow"). The predicate
        // that decides whether a row is counted lives in coverage_poi_shown();
        // liveCounts() below is the same question asked the slow way, and
        // CoverageCountTest keeps the two answers equal.
        /** @var list<array{letter: string, n: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT cc.letter, SUM(cc.n) AS n FROM coverage_count cc
             WHERE cc.letter IN '.self::POI_LETTERS_SQL
               .$this->scopeArm('cc', $rids, $cc).'
             GROUP BY cc.letter HAVING SUM(cc.n) > 0 ORDER BY cc.letter',
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
     * The same counts, computed from the coverage rows themselves. Slow by
     * design; exists so a test can prove the kept table tells the truth.
     *
     * @param list<int> $rids
     *
     * @return array<string, int>
     */
    public function liveCounts(array $rids = [], ?string $cc = null): array
    {
        $params = [];
        $types = [];
        $this->scopeBind($params, $types, $rids, $cc);
        /** @var list<array{letter: string, n: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT cp.letter, COUNT(*) AS n FROM coverage_poi cp
             WHERE cp.letter IN '.self::POI_LETTERS_SQL.'
               AND NOT EXISTS (SELECT 1 FROM item i WHERE cp.ref IN (i.source_ref, i.osm_ref) AND i.state IN '.ItemState::servedSqlTuple().' AND NOT ('.CoverageRetirement::untouchedOsmSql('i').'))'
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
     * Curated overlay: attributes + name, plus confirmation tallies matching ItemConfirmationService::snapshot().
     *
     * A scenic view's photos pass ScenicPhotoRule against the item's own pin,
     * the same filter CatalogProvider applies, so the two ways into the drawer
     * show the same photos.
     *
     * @return array{itemId: int, state: string, fields: object, confirmations: object}
     */
    private function curatedOverlay(int $itemId, string $state, string $name, string $attributesJson, string $letter, ?float $pinLat, ?float $pinLng): array
    {
        /** @var array<string, mixed> $fields */
        $fields = json_decode($attributesJson, true, 512, \JSON_THROW_ON_ERROR);
        if (ScenicPhotoRule::appliesTo($letter)) {
            $fields = ScenicPhotoRule::filterAttributes($fields, $pinLat, $pinLng);
        }
        if ('' !== $name) {
            $fields[Item::NAME_FIELD] = $name;
        }

        /** @var list<array{stance: string, n: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            "SELECT stance, COUNT(*) AS n FROM item_confirmation WHERE item_id = :id AND source <> 'form' GROUP BY stance ORDER BY stance",
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
