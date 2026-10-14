<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Planner;

use Doctrine\DBAL\Connection;

/**
 * One short planned ride, as the planner sends it and as the table holds it
 * (docs/specs/route-planner.md section 6.1): two stops, a three-point line of
 * about two kilometres, and figures that match its geodesic length.
 */
trait RideFixtures
{
    /** @return array{type: string, coordinates: list<array{0: float, 1: float}>} */
    private static function line(): array
    {
        // GeoJSON order, [lng, lat], as every planner call takes its points.
        return ['type' => 'LineString', 'coordinates' => [[5.8633, 50.4925], [5.8630, 50.4835], [5.8627, 50.4745]]];
    }

    /** @return list<array<string, mixed>> */
    private static function stops(): array
    {
        return [
            ['lat' => 50.4925, 'lng' => 5.8633, 'name' => 'Start'],
            ['lat' => 50.4745, 'lng' => 5.8627, 'item' => 'B:12', 'name' => 'Fontaine'],
        ];
    }

    /** The line's geodesic length in metres, measured where the server measures it. */
    private static function lengthOf(Connection $db, array $line): float
    {
        return (float) $db->fetchOne(
            'SELECT ST_Length(ST_GeomFromGeoJSON(:line)::geography)',
            ['line' => json_encode($line, \JSON_THROW_ON_ERROR)],
        );
    }

    /**
     * A valid save body, with any field replaced.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function body(Connection $db, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sunday loop',
            'bike' => 'Road',
            'stops' => self::stops(),
            'line' => self::line(),
            'distanceM' => (int) round(self::lengthOf($db, self::line())),
            'ascentM' => 120,
            'descentM' => 118,
        ], $overrides);
    }

    /**
     * One stored ride for a rider, straight into the table; answers its id.
     *
     * @param string $at an SQL timestamp expression for both dates, so a test
     *                   that watches `updated_at` move compares the database's
     *                   clock with itself
     */
    private static function seedRide(Connection $db, int $userId, string $name = 'Ride of A', string $at = 'NOW()'): int
    {
        return (int) $db->fetchOne(
            "INSERT INTO planned_ride (user_id, name, bike, stops, line, distance_m, ascent_m, descent_m, created_at, updated_at)
             VALUES (:u, :name, 'Gravel', CAST(:stops AS jsonb), ST_SetSRID(ST_GeomFromGeoJSON(:line), 4326), :d, 120, 118, {$at}, {$at})
             RETURNING id",
            [
                'u' => $userId, 'name' => $name,
                'stops' => json_encode(self::stops(), \JSON_THROW_ON_ERROR),
                'line' => json_encode(self::line(), \JSON_THROW_ON_ERROR),
                'd' => (int) round(self::lengthOf($db, self::line())),
            ],
        );
    }

    /** The cap's worth of rides for one rider, in one statement. */
    private static function seedRides(Connection $db, int $userId, int $count): void
    {
        $db->executeStatement(
            "INSERT INTO planned_ride (user_id, name, bike, stops, line, distance_m, ascent_m, descent_m, created_at, updated_at)
             SELECT :u, 'Ride ' || g, 'Road', CAST(:stops AS jsonb), ST_SetSRID(ST_GeomFromGeoJSON(:line), 4326), :d, 0, 0, NOW(), NOW()
               FROM generate_series(1, :n) AS g",
            [
                'u' => $userId, 'n' => $count,
                'stops' => json_encode(self::stops(), \JSON_THROW_ON_ERROR),
                'line' => json_encode(self::line(), \JSON_THROW_ON_ERROR),
                'd' => (int) round(self::lengthOf($db, self::line())),
            ],
        );
    }
}
