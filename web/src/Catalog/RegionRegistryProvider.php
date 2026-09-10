<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

/**
 * Client-side region registry for the scope selector. Labels come from messages, never from here.
 *
 * @see docs/specs/map-and-search.md §4.5
 *
 * @api
 */
final class RegionRegistryProvider
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * `countryCode` matches the CCScope contract. `bbox` is true extent; camera framing uses the largest outline ring.
     *
     * A `bbox` whose **west value is greater than its east value** crosses the
     * antimeridian and is read the long way round, which is the GeoJSON
     * convention (RFC 7946 §5.2). Two of the countries we carry do it: the
     * United States through the Aleutians and New Zealand through the Chathams.
     * Client code must never compare `bbox[0] <= lng && lng <= bbox[2]`; the
     * helpers in assets/map/scope.js exist so it does not have to.
     *
     * @return list<array{id: int, slug: string, countryCode: string, bbox: array{0: float, 1: float, 2: float, 3: float}, adj: list<int>, outline: list<list<float>>, defaultMode: string}>
     */
    public function all(): array
    {
        /** @var list<array{id: int, slug: string, cc: string, w: float, s: float, e: float, n: float, adj: string, outline: ?string, default_map_mode: string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, slug, country_code AS cc,
                    ST_YMin(geom) AS s, ST_YMax(geom) AS n,
                    /* Longitude, the seam-aware way. ST_XMin/ST_XMax are a
                       minimum and a maximum over numbers and know nothing about
                       ±180, so a region straddling it reports -180 to 180: a box
                       359 degrees wide that contains everywhere. ST_ShiftLongitude
                       moves the geometry into a continuous 0..360 space where the
                       seam is not a discontinuity; the shifted edges are then
                       mapped back and come out west > east, which is how GeoJSON
                       writes a crossing box (RFC 7946 §5.2).

                       The test is the RAW span, not a comparison of the two
                       spans. Shifting adds 360 to a negative longitude and the
                       result cannot hold the original mantissa, so every region
                       in the western hemisphere comes back about 1e-14 narrower
                       and "is the shifted span smaller?" says yes for Madrid and
                       Asturias too. It happens to map back to the same numbers
                       for them, so the answer was right by luck; a span wider
                       than 180 degrees says what is actually meant, since only a
                       box reaching from one edge of the seam to the other has
                       one. */
                    CASE WHEN ST_XMax(geom) - ST_XMin(geom) > 180
                         THEN CASE WHEN ST_XMin(ST_ShiftLongitude(geom)) > 180
                                   THEN ST_XMin(ST_ShiftLongitude(geom)) - 360
                                   ELSE ST_XMin(ST_ShiftLongitude(geom)) END
                         ELSE ST_XMin(geom) END AS w,
                    CASE WHEN ST_XMax(geom) - ST_XMin(geom) > 180
                         THEN CASE WHEN ST_XMax(ST_ShiftLongitude(geom)) > 180
                                   THEN ST_XMax(ST_ShiftLongitude(geom)) - 360
                                   ELSE ST_XMax(ST_ShiftLongitude(geom)) END
                         ELSE ST_XMax(geom) END AS e,
                    to_json(COALESCE(adj, ARRAY[]::int[])) AS adj,
                    outline, default_map_mode
             FROM region
             WHERE geom IS NOT NULL AND country_code <> \'\'
               AND '.OperationalRegions::predicate('region').'
             ORDER BY area_km2 DESC, slug',
        );

        $known = [];
        foreach ($rows as $r) {
            $known[(int) $r['id']] = true;
        }

        return array_map(static function (array $r) use ($known): array {
            // Do not 500 the map page on a hand-edited outline; ranking falls back to bbox centres.
            $outline = null === $r['outline'] ? null : json_decode((string) $r['outline'], true);

            return [
                'id' => (int) $r['id'],
                'slug' => (string) $r['slug'],
                'countryCode' => (string) $r['cc'],
                'bbox' => [(float) $r['w'], (float) $r['s'], (float) $r['e'], (float) $r['n']],
                /* Camera box is the largest outline ring, not true bbox — distant islands would frame empty ocean. */
                'view' => self::mainPartBox(\is_array($outline) ? $outline : [])
                    ?? [(float) $r['w'], (float) $r['s'], (float) $r['e'], (float) $r['n']],
                // Filtered against the ids this registry actually carries: a
                // stale adj row can still name a region it does not (a level-2
                // country outline, a region since removed), and the client
                // unions every neighbour id into the spotlight's clear hole.
                // One dangling id there lights up a whole country.
                'adj' => array_values(array_filter(
                    array_map('intval', json_decode((string) $r['adj'], true, 512, \JSON_THROW_ON_ERROR)),
                    static fn (int $id): bool => isset($known[$id]),
                )),
                'outline' => \is_array($outline) ? $outline : [],
                // Toggle token (`all` not `everything`). See MapViewMode::clientToken.
                'defaultMode' => 'everything' === $r['default_map_mode'] ? 'all' : (string) $r['default_map_mode'],
            ];
        }, $rows);
    }

    /**
     * Bounding box of the largest outline ring (cos-latitude-corrected area, not vertex count).
     *
     * @param list<list<float>> $outline flat [x,y,x,y,…] rings
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private static function mainPartBox(array $outline): ?array
    {
        $best = null;
        $bestArea = 0.0;
        foreach ($outline as $ring) {
            // Fewer than three points encloses nothing; the outline is
            // machine-written, so this is a rounding artifact rather than data.
            if (\count($ring) < 6) {
                continue;
            }
            $minX = $minY = \INF;
            $maxX = $maxY = -\INF;
            for ($i = 0, $n = \count($ring) - 1; $i < $n; $i += 2) {
                $x = (float) $ring[$i];
                $y = (float) $ring[$i + 1];
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
            if (\INF === $minX) {
                continue;
            }
            $area = ($maxX - $minX) * cos(deg2rad(($minY + $maxY) / 2)) * ($maxY - $minY);
            if ($area > $bestArea) {
                $bestArea = $area;
                $best = [$minX, $minY, $maxX, $maxY];
            }
        }

        return $best;
    }
}
