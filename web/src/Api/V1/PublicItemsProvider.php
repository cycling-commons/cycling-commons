<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Api\V1;

use App\Api\V1\Dto\ItemDetail;
use App\Api\V1\Dto\ItemFeature;
use App\Api\V1\Dto\PhotoEntry;
use App\Api\V1\Dto\RouteFeature;
use App\BulkExport\BulkExportBuilder;
use App\Catalog\CoverageRetirement;
use App\Catalog\GoneRows;
use App\Catalog\ItemEvidenceResolver;
use App\Catalog\ItemState;
use App\Catalog\ItemType;
use App\Links\WebsiteVerdict;
use App\Links\WebsiteVerdictStore;
use App\Media\PhotoPlace;
use App\Media\PhotoValidator;
use App\Media\PublicPhotos;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The item read paths of /v1 (docs/specs/public-api.md §2.2): search, one
 * item, and the counts in an area. Must not join contributor identity
 * (docs/specs/public-api-personal-data-boundary.md).
 *
 * The map's own worldwide search asks the same endpoint
 * (docs/specs/map-and-search.md §7.1), so a consumer and the map find the
 * same places for the same words.
 *
 * @api
 */
final class PublicItemsProvider
{
    /**
     * Accented letters and their plain forms, so "cote" finds "Côte". The
     * name index (Version20260928180000) is built on exactly this expression,
     * so it must not change without a new index.
     */
    public const string FOLD_FROM = 'àáâãäåāçćčèéêëēěìíîïīñńòóôõöøōùúûüūýÿžšł';
    public const string FOLD_TO = 'aaaaaaaccceeeeeeiiiiinnooooooouuuuuyyzsl';

    /** The folded form of a name column, as the index holds it. */
    public static function foldSql(string $column): string
    {
        return sprintf("translate(lower(%s), '%s', '%s')", $column, self::FOLD_FROM, self::FOLD_TO);
    }

    /** The folded form of a query, matching {@see foldSql()}. */
    public static function fold(string $text): string
    {
        return strtr(mb_strtolower($text), array_combine(mb_str_split(self::FOLD_FROM), mb_str_split(self::FOLD_TO)));
    }

    /**
     * The source a row is filed under, as the bulk export names it
     * (api-strategy.md §3.1): ours, a mirrored copy, or a registered provider.
     */
    private const string SOURCE_SQL = "CASE WHEN i.source IN ('user', 'scout', 'manual', 'auto') THEN '".BulkExportBuilder::OURS."'
                 WHEN i.source = 'authority' THEN COALESCE(dp.provider_key, 'authority') ELSE i.source END";

    public function __construct(
        private readonly Connection $db,
        // The same evidence the map draws (data-provider-hierarchy.md §6.7.7),
        // published as the trust envelope: never re-derived here.
        private readonly ItemEvidenceResolver $evidence,
        private readonly PublicFields $fields,
        private readonly OsmSubsets $osm,
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $siteUrl,
    ) {
    }

    /**
     * Items, routes, or both, in a box, by name, or both: what `/v1/search`
     * answers. Routes (letter R) come only when asked for, with `letter=R` or
     * `$withRoutes`, so a consumer that never asked keeps the answer it had.
     * With a name, the hits whose name starts with it come first, items
     * before routes among equals.
     *
     * A browse without a name pages: items by id first, then routes by id,
     * and `next` holds where the following page starts (`i` the last item,
     * `r` the last route once the items are done). A name search is one page
     * of its best matches and has no next.
     *
     * @param array{0: float, 1: float, 2: float, 3: float}|null $bbox
     * @param 'community'|'curated'|null                         $tier
     * @param array<string, int|string>|null                     $after a cursor's keys
     *
     * @return array{features: list<ItemFeature|RouteFeature>, next: array<string, int>|null}
     */
    public function search(?string $letter, ?array $bbox, ?string $q, int $limit, ?string $tier, bool $withRoutes, bool $hydrate = false, ?array $after = null): array
    {
        $wantItems = 'R' !== $letter;
        $wantRoutes = 'R' === $letter || (null === $letter && $withRoutes);

        if (null !== $q) {
            $items = $wantItems ? $this->features($letter, $bbox, $q, $limit, $tier) : [];
            $routes = $wantRoutes ? $this->routes($bbox, $q, $limit, $tier) : [];
            $hits = array_merge($this->hydrated($items, $hydrate), $routes);
            if ([] !== $items && [] !== $routes) {
                $starts = static fn (ItemFeature|RouteFeature $f): int => str_starts_with(self::fold($f->name), self::fold($q)) ? 0 : 1;
                // usort is stable: each list keeps its own ranking inside a group.
                usort($hits, static fn (ItemFeature|RouteFeature $a, ItemFeature|RouteFeature $b): int => $starts($a) <=> $starts($b));
            }

            return ['features' => \array_slice($hits, 0, $limit), 'next' => null];
        }

        $page = [];
        // A cursor that carries `r` has finished the items.
        $itemsDone = !$wantItems || isset($after['r']);
        if (!$itemsDone) {
            $items = $this->features($letter, $bbox, null, $limit + 1, $tier, (int) ($after['i'] ?? 0));
            if (\count($items) > $limit) {
                $items = \array_slice($items, 0, $limit);

                return ['features' => $this->hydrated($items, $hydrate), 'next' => ['i' => end($items)->id]];
            }
            $page = $this->hydrated($items, $hydrate);
        }
        if (!$wantRoutes) {
            return ['features' => $page, 'next' => null];
        }
        $room = $limit - \count($page);
        $routeAfter = (int) ($after['r'] ?? 0);
        $routes = $this->routes($bbox, null, $room + 1, $tier, $routeAfter);
        if (\count($routes) > $room) {
            $routes = \array_slice($routes, 0, $room);

            return ['features' => [...$page, ...$routes], 'next' => ['r' => [] !== $routes ? end($routes)->id : $routeAfter]];
        }

        return ['features' => [...$page, ...$routes], 'next' => null];
    }

    /**
     * One served item in full, or null: not served, retired, reported gone
     * or never there are all the same 404 to a consumer.
     */
    public function item(int $id, bool $hydrate): ?ItemDetail
    {
        $row = $this->db->fetchAssociative(
            'SELECT i.id, i.name, i.letter, ST_AsGeoJSON(i.geom) AS geom, (i.state = \'verified\') AS verified,
                    i.state, i.source, i.imported_at, i.country_code, i.osm_ref, i.source_ref, i.attributes,
                    ST_Y(ST_PointOnSurface(i.geom)) AS pin_lat, ST_X(ST_PointOnSurface(i.geom)) AS pin_lng,
                    r.slug AS region, '.self::SOURCE_SQL.' AS public_source, '.ItemEvidenceResolver::selectSql('i').',
                    wc.verdict AS web_verdict
             FROM item i
             LEFT JOIN region r ON r.id = i.region_id
             LEFT JOIN data_provider dp ON dp.id = i.provider_id
             LEFT JOIN website_check wc ON wc.host = '.WebsiteVerdictStore::hostSql("i.attributes->>'web'").'
             WHERE i.id = :id AND '.BulkExportBuilder::servedItemSql('i'),
            ['id' => $id],
        );
        if (false === $row) {
            return null;
        }
        /** @var array{id: int|string, name: string, letter: string, geom: string, verified: bool, state: string, source: string, imported_at: string|null, country_code: string|null, osm_ref: string|null, source_ref: string|null, attributes: string, pin_lat: float|string|null, pin_lng: float|string|null, region: string|null, public_source: string, ev_provider: bool, ev_scope: bool|null, ev_conf: int|string, ev_last: string|null, ev_witness: string|null, ev_reclaimed: string|null, web_verdict: string|null} $row */
        $geometry = json_decode($row['geom'], true);
        if (!\is_array($geometry)) {
            return null;
        }
        $type = ItemType::fromLetter($row['letter']);
        /** @var array<string, mixed> $stored */
        $stored = json_decode($row['attributes'], true) ?: [];
        // A dead or parked website is served to nobody (docs/specs/website-liveness.md section 3).
        if (WebsiteVerdict::hides($row['web_verdict'])) {
            unset($stored['web']);
        }
        // The photos the map shows, minus the keys no public payload carries.
        $shown = PublicPhotos::of(PhotoValidator::sift($stored, PhotoPlace::of($row['letter'], $row['pin_lat'], $row['pin_lng']))['attributes']);
        $kindField = null !== $type ? PublicFields::kindField($type) : null;
        $kind = null !== $kindField && \is_string($stored[$kindField] ?? null) && '' !== $stored[$kindField] ? $stored[$kindField] : null;
        $osmRef = self::osmRefOf($row['osm_ref'], $row['source'], $row['source_ref']);

        $feature = new ItemFeature(
            (int) $row['id'],
            $row['letter'],
            $row['name'],
            $row['verified'] ? 'curated' : 'community',
            $geometry,
            $this->evidence->fromRow($row, new \DateTimeImmutable()),
            $row['region'],
        );

        return new ItemDetail(
            $feature,
            (string) $row['country_code'],
            $osmRef,
            $row['public_source'],
            $kind,
            null !== $type ? $this->fields->servedOf($type, $stored) : [],
            PhotoEntry::allOf($shown, $this->siteUrl),
            $hydrate && null !== $osmRef ? ($this->osm->byRef([$osmRef])[$osmRef] ?? null) : null,
            $hydrate,
        );
    }

    /**
     * Per-letter counts in a box: the items `/v1/search` serves, the
     * recommended routes (R), and apart from both the OpenStreetMap points
     * the coverage tiles draw that no item stands for.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     *
     * @return array{items: array<string, int>, coverage: array<string, int>}
     */
    public function counts(array $bbox): array
    {
        $box = ['minLon' => $bbox[0], 'minLat' => $bbox[1], 'maxLon' => $bbox[2], 'maxLat' => $bbox[3]];
        $envelope = 'ST_MakeEnvelope(:minLon, :minLat, :maxLon, :maxLat, 4326)';

        $items = [];
        /** @var list<array{letter: string, n: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT i.letter, COUNT(*) AS n FROM item i
             WHERE i.geom && '.$envelope.' AND '.BulkExportBuilder::servedItemSql('i').'
             GROUP BY i.letter',
            $box,
        );
        foreach ($rows as $row) {
            $items[$row['letter']] = (int) $row['n'];
        }
        $routes = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM recommended_route rr WHERE rr.geom && '.$envelope.' AND rr.state IN '.ItemState::servedSqlTuple(),
            $box,
        );
        if ($routes > 0) {
            $items['R'] = $routes;
        }
        ksort($items);

        $coverage = [];
        if (null !== $this->db->fetchOne("SELECT to_regclass('public.coverage_poi')")) {
            $letters = "('".implode("', '", array_map(strtoupper(...), CategoryTable::COVERAGE_LETTERS))."')";
            /** @var list<array{letter: string, n: int|string}> $rows */
            $rows = $this->db->fetchAllAssociative(
                'SELECT cp.letter, COUNT(*) AS n FROM coverage_poi cp
                 WHERE cp.geom && '.$envelope.' AND cp.letter IN '.$letters.'
                   AND NOT EXISTS (SELECT 1 FROM item i WHERE cp.ref IN (i.source_ref, i.osm_ref) AND i.state IN '.ItemState::servedSqlTuple().' AND NOT ('.CoverageRetirement::untouchedOsmSql('i').'))
                 GROUP BY cp.letter ORDER BY cp.letter',
                $box,
            );
            foreach ($rows as $row) {
                $coverage[$row['letter']] = (int) $row['n'];
            }
        }

        return ['items' => $items, 'coverage' => $coverage];
    }

    /**
     * The OpenStreetMap object an item stands for: its own answer, or the
     * ref a copy taken from OpenStreetMap was filed under.
     */
    private static function osmRefOf(?string $osmRef, string $source, ?string $sourceRef): ?string
    {
        if (null !== $osmRef && '' !== $osmRef) {
            return $osmRef;
        }

        return 'osm' === $source && null !== $sourceRef && 1 === preg_match('~^(node|way)/\d+$~', $sourceRef) ? $sourceRef : null;
    }

    /**
     * @param list<ItemFeature> $items
     *
     * @return list<ItemFeature>
     */
    private function hydrated(array $items, bool $hydrate): array
    {
        if (!$hydrate || [] === $items) {
            return $items;
        }
        $refs = $this->db->fetchAllKeyValue(
            'SELECT i.id, COALESCE(NULLIF(i.osm_ref, \'\'), CASE WHEN i.source = \'osm\' THEN i.source_ref END) FROM item i WHERE i.id IN (:ids)',
            ['ids' => array_map(static fn (ItemFeature $f): int => $f->id, $items)],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
        $subsets = $this->osm->byRef(array_values(array_filter(array_map(strval(...), $refs), static fn (string $r): bool => '' !== $r)));

        return array_map(static function (ItemFeature $f) use ($refs, $subsets): ItemFeature {
            $ref = $refs[$f->id] ?? null;

            return $f->withOsm(\is_string($ref) ? ($subsets[$ref] ?? null) : null);
        }, $items);
    }

    /**
     * Served recommended routes, as one point each.
     *
     * @param array{0: float, 1: float, 2: float, 3: float}|null $bbox
     * @param 'community'|'curated'|null                         $tier
     * @param int                                                $afterId only routes after this id (a cursor)
     *
     * @return list<RouteFeature>
     */
    public function routes(?array $bbox, ?string $q, int $limit, ?string $tier = null, int $afterId = 0): array
    {
        $sql = "SELECT rr.id, rr.name, ST_AsGeoJSON(ST_PointOnSurface(rr.geom)) AS geom, (rr.state = 'verified') AS verified,
                       rr.distance_m, rr.ascent_m, r.slug AS region
                FROM recommended_route rr
                LEFT JOIN region r ON r.id = rr.region_id
                WHERE rr.state IN ".ItemState::servedSqlTuple();
        $params = ['lim' => $limit];
        if (null !== $bbox) {
            $sql .= ' AND rr.geom && ST_MakeEnvelope(:minLon, :minLat, :maxLon, :maxLat, 4326)';
            $params += ['minLon' => $bbox[0], 'minLat' => $bbox[1], 'maxLon' => $bbox[2], 'maxLat' => $bbox[3]];
        }
        if ($afterId > 0) {
            $sql .= ' AND rr.id > :after';
            $params['after'] = $afterId;
        }
        $order = 'rr.id';
        if (null !== $q) {
            $folded = self::fold($q);
            $like = addcslashes($folded, '%_\\');
            $name = self::foldSql('rr.name');
            $sql .= ' AND '.$name." LIKE :contains ESCAPE '\\'";
            $params += ['contains' => '%'.$like.'%', 'starts' => $like.'%', 'q' => $folded];
            $order = '('.$name." LIKE :starts ESCAPE '\\') DESC, similarity(".$name.', :q) DESC, rr.id';
        }
        if ('curated' === $tier) {
            $sql .= " AND rr.state = 'verified'";
        } elseif ('community' === $tier) {
            $sql .= " AND rr.state <> 'verified'";
        }

        /** @var list<array{id: int|string, name: string, geom: string, verified: bool, distance_m: int|string, ascent_m: int|string, region: string|null}> $rows */
        $rows = $this->db->fetchAllAssociative($sql.' ORDER BY '.$order.' LIMIT :lim', $params, ['lim' => ParameterType::INTEGER]);
        $features = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed>|null $geometry */
            $geometry = json_decode((string) $row['geom'], true);
            if (!\is_array($geometry)) {
                continue;
            }
            $features[] = new RouteFeature(
                (int) $row['id'],
                (string) $row['name'],
                $row['verified'] ? 'curated' : 'community',
                $geometry,
                (int) $row['distance_m'],
                (int) $row['ascent_m'],
                $row['region'],
            );
        }

        return $features;
    }

    /**
     * Served items in a box, by name, or both. With a name, the names that
     * start with it come first, then the closest matches; without one, the
     * order is the id's.
     *
     * @param string|null                                        $letter  one catalogue letter, or null for all letters
     * @param array{0: float, 1: float, 2: float, 3: float}|null $bbox    minLon,minLat,maxLon,maxLat (WGS84), or null for anywhere
     * @param string|null                                        $q       words in the name, or null for any name
     * @param 'community'|'curated'|null                         $tier    null serves both tiers
     * @param int                                                $afterId only items after this id (a cursor)
     *
     * @return list<ItemFeature>
     */
    public function features(?string $letter, ?array $bbox, ?string $q, int $limit, ?string $tier = null, int $afterId = 0): array
    {
        // Named once so SELECT and the tier filter cannot disagree; no
        // contributor join. One definition of verified, shared with the map and
        // the readiness gate (owner 2026-09-09): the state column, reached by
        // `map.item_verify_threshold` riders or by one curator. Neither a lone
        // confirmation nor an `authority` provenance stands in for it any more.
        $verified = '(i.state = \'verified\')';

        // The region by its public slug, the key /v1/regions uses: a
        // consumer, and the map, can load the region a hit lies in.
        $sql = 'SELECT i.id, i.name, i.letter, ST_AsGeoJSON(i.geom) AS geom, '.$verified.' AS verified,
                       i.state, i.source, i.imported_at, r.slug AS region, '.ItemEvidenceResolver::selectSql('i').'
                FROM item i
                LEFT JOIN region r ON r.id = i.region_id
                WHERE i.state IN '.ItemState::servedSqlTuple();
        $params = ['lim' => $limit];
        if (null !== $bbox) {
            $sql .= ' AND i.geom && ST_MakeEnvelope(:minLon, :minLat, :maxLon, :maxLat, 4326)';
            $params += ['minLon' => $bbox[0], 'minLat' => $bbox[1], 'maxLon' => $bbox[2], 'maxLat' => $bbox[3]];
        }
        if ($afterId > 0) {
            $sql .= ' AND i.id > :after';
            $params['after'] = $afterId;
        }
        $order = 'i.id';
        if (null !== $q) {
            $folded = self::fold($q);
            $like = addcslashes($folded, '%_\\');
            $name = self::foldSql('i.name');
            $sql .= ' AND '.$name." LIKE :contains ESCAPE '\\'";
            $params += ['contains' => '%'.$like.'%', 'starts' => $like.'%', 'q' => $folded];
            $order = '('.$name." LIKE :starts ESCAPE '\\') DESC, similarity(".$name.', :q) DESC, i.id';
        }

        if (null !== $letter) {
            $sql .= ' AND i.letter = :letter';
            $params['letter'] = $letter;
            if (\in_array($letter, CoverageRetirement::LETTERS, true)) {
                $sql .= ' AND NOT ('.CoverageRetirement::untouchedOsmSql('i').')';
            }
        } else {
            // Retirement exclusion stays scoped to coverage letters.
            $sql .= ' AND NOT (i.letter IN '.CoverageRetirement::lettersSqlTuple().' AND ('.CoverageRetirement::untouchedOsmSql('i').'))';
        }
        if ('curated' === $tier) {
            $sql .= ' AND '.$verified;
        } elseif ('community' === $tier) {
            $sql .= ' AND NOT '.$verified;
        }
        $sql .= ' AND '.GoneRows::notGoneSql('i').'
                  ORDER BY '.$order.'
                  LIMIT :lim';

        /** @var list<array{id: int|string, name: string, letter: string, geom: string, verified: bool, state: string, source: string, imported_at: string|null, region: string|null, ev_provider: bool, ev_scope: bool|null, ev_conf: int|string, ev_last: string|null, ev_witness: string|null, ev_reclaimed: string|null}> $rows */
        $rows = $this->db->fetchAllAssociative($sql, $params, ['lim' => ParameterType::INTEGER]);
        $now = new \DateTimeImmutable();

        $features = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed>|null $geometry */
            $geometry = json_decode((string) $row['geom'], true);
            if (!\is_array($geometry)) {
                continue;   // a row with unparseable geometry is not a feature
            }
            $features[] = new ItemFeature(
                (int) $row['id'],
                (string) $row['letter'],
                (string) $row['name'],
                $row['verified'] ? 'curated' : 'community',
                $geometry,
                $this->evidence->fromRow($row, $now),
                $row['region'],
            );
        }

        return $features;
    }
}
