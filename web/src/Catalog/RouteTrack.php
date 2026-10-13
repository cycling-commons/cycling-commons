<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

/**
 * A served route as a track to download: its name and its line. The one
 * lookup behind both GPX downloads, the site's `/routes/{id}.gpx` and the
 * API's `/v1/routes/{id}.gpx` (route-domain.md §4.3, public-api.md §2.2), so
 * they cannot disagree on which routes may be downloaded.
 *
 * @api
 */
final readonly class RouteTrack
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * Null when the id names no served route, or its line is not one track.
     *
     * @return array{name: string, coordinates: list<array{0: float, 1: float}>}|null
     */
    public function find(int $id): ?array
    {
        /** @var array{name: string, geom: string}|false $row */
        $row = $this->db->fetchAssociative(
            'SELECT name, ST_AsGeoJSON(geom) AS geom FROM recommended_route
             WHERE id = :id AND geom IS NOT NULL AND state IN '.ItemState::servedSqlTuple(),
            ['id' => $id],
        );
        if (false === $row) {
            return null;
        }

        $geo = json_decode($row['geom'], true);
        if (!\is_array($geo) || 'LineString' !== ($geo['type'] ?? null) || !\is_array($geo['coordinates'] ?? null)) {
            return null;
        }

        /** @var list<array{0: float, 1: float}> $coordinates */
        $coordinates = $geo['coordinates'];

        return ['name' => $row['name'], 'coordinates' => $coordinates];
    }

    /** The download's file name, without `.gpx`. */
    public static function fileName(string $name): string
    {
        return strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-')) ?: 'route';
    }
}
