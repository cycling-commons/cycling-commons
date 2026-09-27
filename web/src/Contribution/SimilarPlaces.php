<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

use App\Catalog\ClaimedOsmRefs;
use App\Catalog\ItemState;
use App\Catalog\ItemType;
use App\Moderation\ReplacedPlaces;
use Doctrine\DBAL\Connection;

/**
 * The places near a new pin that may be the same place, for the wizard.
 *
 * Shown under the pin to everyone who adds or corrects a place (owner
 * 2026-09-27: "I should see a message that similar item(s) are found within
 * 250 meters"). Two sources, nearest first, at most
 * {@see ReplacedPlaces::MAX_TICKS}:
 *
 * - served items of the same letter, ours and providers' alike;
 * - OpenStreetMap points of the same letter no served item holds yet.
 *
 * A letter is not always a kind. Letter B is water AND food, and a bakery is
 * not a tap: for B the OSM food points are left out by the same rule the
 * pipeline marks them `food` with (pipeline/coverage/tiles.py). Every place
 * in letter B's catalogue is water.
 *
 * What the rider leaves ticked travels as `_replaces` and is retired only
 * when a curator approves ({@see ReplacedPlaces}).
 *
 * @see docs/specs/catalog-data-model.md §5a
 *
 * @api
 */
final readonly class SimilarPlaces
{
    /** Pre-ticked in the wizard: as close as a provider's own match radius. */
    public const int TICKED_WITHIN_M = 50;

    /**
     * OSM food, as pipeline/coverage/tiles.py's `food` tile property. The key
     * test is `->> IS NOT NULL`, not jsonb's `?`, which the database layer
     * would read as a placeholder.
     */
    private const string OSM_FOOD_SQL = "(cp.tags->>'shop' IS NOT NULL OR cp.tags->>'amenity' IN ('cafe', 'fast_food', 'restaurant', 'bar', 'pub'))";

    public function __construct(private Connection $db)
    {
    }

    /**
     * @return list<array{tick: string, from: string, provider: ?string, name: ?string, metres: int, ticked: bool, lat: float, lng: float}>
     */
    public function near(ItemType $type, float $lat, float $lng, ?int $exceptItem = null, ?string $exceptRef = null): array
    {
        $letter = $type->letter();
        $point = 'ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography';
        $food = ItemType::WaterFood === $type ? ' AND NOT '.self::OSM_FOOD_SQL : '';

        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM (
                SELECT \'item:\' || i.id AS tick,
                       CASE WHEN i.provider_id IS NOT NULL THEN \'provider\' ELSE \'ours\' END AS "from",
                       -- A tap from a provider often has no name; its type says what it is.
                       p.name AS provider, COALESCE(NULLIF(i.name, \'\'), i.attributes->>\'type\') AS name,
                       ST_Distance(i.geom::geography, '.$point.') AS metres,
                       ST_Y(i.geom) AS lat, ST_X(i.geom) AS lng
                  FROM item i
                  LEFT JOIN data_provider p ON p.id = i.provider_id
                 WHERE i.letter = :letter
                   AND i.state IN '.ItemState::servedSqlTuple().'
                   AND i.id <> :except_item
                   AND ST_DWithin(i.geom::geography, '.$point.', :radius)
                UNION ALL
                SELECT cp.ref, \'osm\', NULL, cp.name,
                       ST_Distance(cp.geom::geography, '.$point.'),
                       ST_Y(cp.geom), ST_X(cp.geom)
                  FROM coverage_poi cp
                 WHERE cp.letter = :letter
                   AND cp.ref <> :except_ref
                   AND ST_DWithin(cp.geom::geography, '.$point.', :radius)
                   AND cp.ref NOT IN ('.ClaimedOsmRefs::selectSql().')'.$food.'
             ) near
             ORDER BY metres
             LIMIT '.ReplacedPlaces::MAX_TICKS,
            [
                'letter' => $letter,
                'lat' => $lat,
                'lng' => $lng,
                'radius' => ReplacedPlaces::RADIUS_M,
                'except_item' => $exceptItem ?? 0,
                'except_ref' => $exceptRef ?? '',
            ],
        );

        return array_map(static function (array $r): array {
            $metres = (int) round((float) $r['metres']);

            return [
                'tick' => (string) $r['tick'],
                'from' => (string) $r['from'],
                'provider' => null !== $r['provider'] ? (string) $r['provider'] : null,
                'name' => null !== $r['name'] && '' !== $r['name'] ? (string) $r['name'] : null,
                'metres' => $metres,
                'ticked' => $metres <= self::TICKED_WITHIN_M,
                'lat' => round((float) $r['lat'], 6),
                'lng' => round((float) $r['lng'], 6),
            ];
        }, $rows);
    }
}
