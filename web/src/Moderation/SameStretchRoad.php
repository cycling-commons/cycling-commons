<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\Entity\Item;
use App\Catalog\ItemState;
use App\Catalog\ItemType;
use App\Contribution\ChangeValue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The road on the map a new road surface lies on (edit-items/A-road-surface.md, "A road on a stretch we hold adds
 * to it"). A rider who draws a stretch that is already ours adds what they know to that road: approving the new
 * road writes the values it brings onto the road on the map, and the new road is closed into it. Two roads on one
 * stretch would otherwise both draw, or the second would retire the first and lose what only the first knew.
 *
 * The same stretch: each line lies within {@see NEAR_M} of the other for at least {@see SHARE} of its length. A
 * new road that covers only a part of a longer road, or runs on past it, is a stretch of its own.
 *
 * @api
 */
final readonly class SameStretchRoad
{
    /** How far apart two lines on one road may lie, in metres: the two sides of a road, a tap beside it. */
    public const float NEAR_M = 15.0;

    /** How much of each line must lie on the other. */
    public const float SHARE = 0.8;

    public function __construct(private Connection $db)
    {
    }

    /**
     * The served road each of these new road surfaces lies on, by new item id; a road on no served road is absent.
     *
     * @param list<int> $itemIds
     *
     * @return array<int, array{id: int, name: string, attributes: array<string, mixed>, route: list<array{0: float, 1: float}>}>
     */
    public function servedFor(array $itemIds): array
    {
        if ([] === $itemIds) {
            return [];
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT DISTINCT ON (n.id) n.id AS new_id, o.id, o.name, o.attributes, ST_AsGeoJSON(o.geom) AS line
               FROM item n
               JOIN item o ON o.letter = n.letter AND o.id <> n.id AND o.state IN '.ItemState::servedSqlTuple().'
                          AND ST_DWithin(o.geom::geography, n.geom::geography, :near)
              CROSS JOIN LATERAL (SELECT
                     ST_Length(ST_Intersection(n.geom, ST_Buffer(o.geom::geography, :near)::geometry)::geography)
                       / NULLIF(ST_Length(n.geom::geography), 0) AS n_share,
                     ST_Length(ST_Intersection(o.geom, ST_Buffer(n.geom::geography, :near)::geometry)::geography)
                       / NULLIF(ST_Length(o.geom::geography), 0) AS o_share) s
              WHERE n.id IN (:ids) AND n.letter = :letter
                AND GeometryType(n.geom) = \'LINESTRING\' AND GeometryType(o.geom) = \'LINESTRING\'
                AND s.n_share >= :share AND s.o_share >= :share
              ORDER BY n.id, s.n_share + s.o_share DESC, o.id',
            ['ids' => $itemIds, 'letter' => ItemType::RoadSurface->letter(), 'near' => self::NEAR_M, 'share' => self::SHARE],
            ['ids' => ArrayParameterType::INTEGER],
        );
        $out = [];
        foreach ($rows as $r) {
            $attributes = json_decode((string) $r['attributes'], true);
            $out[(int) $r['new_id']] = [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'attributes' => \is_array($attributes) ? $attributes : [],
                'route' => self::route((string) $r['line']),
            ];
        }

        return $out;
    }

    /** The served road this new road surface lies on, or null. */
    public function servedIdFor(int $itemId): ?int
    {
        return $this->servedFor([$itemId])[$itemId]['id'] ?? null;
    }

    /**
     * What a new road brings to the road it lies on, as {was, now} per field: every value it gives that the road
     * does not hold, the name among them. Its line and its OSM ways are not values: the road keeps its own.
     *
     * @param array<string, mixed> $roadAttributes the road on the map
     * @param array<string, mixed> $newAttributes  the new road
     *
     * @return array<string, array{was: mixed, now: mixed}>
     */
    public static function additions(string $roadName, array $roadAttributes, string $newName, array $newAttributes): array
    {
        $out = [];
        if ('' !== trim($newName) && $newName !== $roadName) {
            $out[Item::NAME_FIELD] = ['was' => '' === $roadName ? null : $roadName, 'now' => $newName];
        }
        foreach ($newAttributes as $field => $now) {
            if ('segment' === $field || str_starts_with((string) $field, '_') || \in_array($field, ChangeValue::NOT_A_ROW, true)
                || null === $now || '' === $now || [] === $now) {
                continue;
            }
            $was = $roadAttributes[$field] ?? null;
            if ($was !== $now) {
                $out[(string) $field] = ['was' => $was, 'now' => $now];
            }
        }

        return $out;
    }

    /**
     * A GeoJSON line as the map's [lat, lng] route.
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function route(string $geojson): array
    {
        $geometry = json_decode($geojson, true);
        $route = [];
        foreach (\is_array($geometry) && \is_array($geometry['coordinates'] ?? null) ? $geometry['coordinates'] : [] as $p) {
            if (\is_array($p) && is_numeric($p[0] ?? null) && is_numeric($p[1] ?? null)) {
                $route[] = [(float) $p[1], (float) $p[0]];
            }
        }

        return $route;
    }
}
