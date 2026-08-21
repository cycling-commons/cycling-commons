<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
     * @return list<array{id: int, slug: string, countryCode: string, bbox: array{0: float, 1: float, 2: float, 3: float}, adj: list<int>, outline: list<list<float>>, defaultMode: string}>
     */
    public function all(): array
    {
        /** @var list<array{id: int, slug: string, cc: string, w: float, s: float, e: float, n: float, adj: string, outline: ?string, default_map_mode: string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, slug, country_code AS cc,
                    ST_XMin(geom) AS w, ST_YMin(geom) AS s, ST_XMax(geom) AS e, ST_YMax(geom) AS n,
                    to_json(COALESCE(adj, ARRAY[]::int[])) AS adj,
                    outline, default_map_mode
             FROM region
             WHERE geom IS NOT NULL AND country_code <> \'\'
               AND '.OperationalRegions::predicate('region').'
             ORDER BY area_km2 DESC, slug',
        );

        return array_map(static function (array $r): array {
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
                'adj' => array_map('intval', json_decode((string) $r['adj'], true, 512, \JSON_THROW_ON_ERROR)),
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
