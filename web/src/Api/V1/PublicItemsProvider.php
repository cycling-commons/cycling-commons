<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Api\V1;

use App\Api\V1\Dto\ItemFeature;
use App\Api\V1\Dto\RouteFeature;
use App\Catalog\CoverageRetirement;
use App\Catalog\GoneRows;
use App\Catalog\ItemEvidenceResolver;
use App\Catalog\ItemState;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * /v1/search read path (docs/specs/public-api.md §2.2). Must not join
 * contributor identity (docs/specs/public-api-personal-data-boundary.md).
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

    public function __construct(
        private readonly Connection $db,
        // The same evidence the map draws (data-provider-hierarchy.md §6.7.7),
        // published as the trust envelope: never re-derived here.
        private readonly ItemEvidenceResolver $evidence,
    ) {
    }

    /**
     * Items, routes, or both, in a box, by name, or both: what `/v1/search`
     * answers. Routes (letter R) come only when asked for, with `letter=R` or
     * `$withRoutes`, so a consumer that never asked keeps the answer it had.
     * With a name, the hits whose name starts with it come first, items
     * before routes among equals.
     *
     * @param array{0: float, 1: float, 2: float, 3: float}|null $bbox
     * @param 'community'|'curated'|null                         $tier
     *
     * @return list<ItemFeature|RouteFeature>
     */
    public function search(?string $letter, ?array $bbox, ?string $q, int $limit, ?string $tier, bool $withRoutes): array
    {
        $items = 'R' === $letter ? [] : $this->features($letter, $bbox, $q, $limit, $tier);
        $routes = ('R' === $letter || (null === $letter && $withRoutes)) ? $this->routes($bbox, $q, $limit, $tier) : [];
        $hits = array_merge($items, $routes);
        if (null !== $q && [] !== $items && [] !== $routes) {
            $starts = static fn (ItemFeature|RouteFeature $f): int => str_starts_with(self::fold($f->name), self::fold($q)) ? 0 : 1;
            // usort is stable: each list keeps its own ranking inside a group.
            usort($hits, static fn (ItemFeature|RouteFeature $a, ItemFeature|RouteFeature $b): int => $starts($a) <=> $starts($b));
        }

        return \array_slice($hits, 0, $limit);
    }

    /**
     * Served recommended routes, as one point each.
     *
     * @param array{0: float, 1: float, 2: float, 3: float}|null $bbox
     * @param 'community'|'curated'|null                         $tier
     *
     * @return list<RouteFeature>
     */
    public function routes(?array $bbox, ?string $q, int $limit, ?string $tier = null): array
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
     * @param string|null                                        $letter one catalogue letter, or null for all letters
     * @param array{0: float, 1: float, 2: float, 3: float}|null $bbox   minLon,minLat,maxLon,maxLat (WGS84), or null for anywhere
     * @param string|null                                        $q      words in the name, or null for any name
     * @param 'community'|'curated'|null                         $tier   null serves both tiers
     *
     * @return list<ItemFeature>
     */
    public function features(?string $letter, ?array $bbox, ?string $q, int $limit, ?string $tier = null): array
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
