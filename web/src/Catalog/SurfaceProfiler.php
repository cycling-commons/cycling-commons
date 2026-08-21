<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

/**
 * Surfaces a route crosses from served A-layer segments. `parts` and `covered` are measured on different sides so neither can exceed 100%.
 *
 * @see docs/specs/route-domain.md §9
 *
 * @api
 */
final class SurfaceProfiler
{
    public const int BUFFER_M = 25;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Returns null when no usable mapped segment is near the route.
     *
     * @return array{covered: int, parts: list<array{surface: string, pct: int}>}|null
     */
    public function profile(string $lineStringGeoJson): ?array
    {
        /** @var list<array{surface: string, metres: string|float|null}> $partRows */
        $partRows = $this->db->fetchAllAssociative(
            "WITH route AS (SELECT ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326) AS g)
             SELECT i.attributes->>'surface' AS surface,
                    SUM(ST_Length(ST_Intersection(i.geom, ST_Buffer((SELECT g FROM route)::geography, :buf)::geometry)::geography)) AS metres
             FROM item i
             WHERE i.letter = 'A'
               AND i.state IN ".ItemState::servedSqlTuple().'
               AND i.attributes->>\'surface\' IS NOT NULL
               AND i.attributes->>\'surface\' <> \'Surface unverified\'
               AND ST_DWithin(i.geom::geography, (SELECT g FROM route)::geography, :buf)
             GROUP BY i.attributes->>\'surface\'',
            ['geom' => $lineStringGeoJson, 'buf' => self::BUFFER_M],
        );

        if ([] === $partRows) {
            return null;
        }

        $total = 0.0;
        $byMetres = [];
        foreach ($partRows as $row) {
            $metres = (float) $row['metres'];
            if ($metres <= 0.0) {
                continue;
            }
            $total += $metres;
            $byMetres[] = ['surface' => $row['surface'], 'metres' => $metres];
        }
        if ($total <= 0.0) {
            return null;
        }

        // Largest-remainder over top-4 so kept parts cannot round past 100.
        usort($byMetres, static fn (array $a, array $b): int => $b['metres'] <=> $a['metres']);
        $kept = \array_slice($byMetres, 0, 4);

        $floors = [];
        $remainders = [];
        $floorSum = 0;
        $shareSum = 0.0;
        foreach ($kept as $i => $part) {
            $share = 100.0 * $part['metres'] / $total;
            $shareSum += $share;
            $floor = (int) floor($share);
            $floors[$i] = $floor;
            $remainders[$i] = $share - (float) $floor;
            $floorSum += $floor;
        }

        // Ties: longer segment, then length-descending order.
        $order = array_keys($kept);
        usort($order, static function (int $a, int $b) use ($remainders, $kept): int {
            if ($remainders[$a] !== $remainders[$b]) {
                return $remainders[$b] <=> $remainders[$a];
            }
            if ($kept[$a]['metres'] !== $kept[$b]['metres']) {
                return $kept[$b]['metres'] <=> $kept[$a]['metres'];
            }

            return $a <=> $b;
        });

        $pcts = $floors;
        $leftover = (int) round($shareSum) - $floorSum;
        for ($n = 0; $n < $leftover; ++$n) {
            ++$pcts[$order[$n]];
        }

        $parts = [];
        foreach ($kept as $i => $part) {
            if ($pcts[$i] > 0) {
                $parts[] = ['surface' => $part['surface'], 'pct' => $pcts[$i]];
            }
        }
        if ([] === $parts) {
            return null;
        }

        /** @var array{route_len: string|float|null, covered_len: string|float|null} $coverage */
        $coverage = $this->db->fetchAssociative(
            "WITH route AS (SELECT ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326) AS g),
                  usable AS (
                    SELECT i.geom AS g FROM item i
                    WHERE i.letter = 'A'
                      AND i.state IN ".ItemState::servedSqlTuple().'
                      AND i.attributes->>\'surface\' IS NOT NULL
                      AND i.attributes->>\'surface\' <> \'Surface unverified\'
                      AND ST_DWithin(i.geom::geography, (SELECT g FROM route)::geography, :buf)
                  )
             SELECT ST_Length((SELECT g FROM route)::geography) AS route_len,
                    ST_Length(ST_Intersection(
                      (SELECT g FROM route),
                      ST_Buffer((SELECT ST_Union(g) FROM usable)::geography, :buf)::geometry
                    )::geography) AS covered_len',
            ['geom' => $lineStringGeoJson, 'buf' => self::BUFFER_M],
        );

        $routeLen = (float) $coverage['route_len'];
        if ($routeLen <= 0.0) {
            return null;
        }
        $covered = min(100, (int) round(100.0 * (float) $coverage['covered_len'] / $routeLen));
        if ($covered <= 0) {
            return null;
        }

        return ['covered' => $covered, 'parts' => $parts];
    }

    /**
     * Recompute `attributes.surfaces` for every recommended_route. Missing coverage drops the key; other keys are untouched.
     */
    public function recomputeAll(): int
    {
        /** @var list<array{id: int|string, geom: string, attributes: string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, ST_AsGeoJSON(geom) AS geom, attributes::text AS attributes FROM recommended_route ORDER BY id',
        );

        $updated = 0;
        foreach ($rows as $row) {
            /** @var array<string, mixed> $attributes */
            $attributes = json_decode($row['attributes'], true, 512, \JSON_THROW_ON_ERROR);
            $profile = $this->profile($row['geom']);
            $had = \array_key_exists('surfaces', $attributes);

            if (null === $profile) {
                if (!$had) {
                    continue; // never mapped, still isn't: nothing to write
                }
                unset($attributes['surfaces']);
            } elseif ($had && self::sameProfile($attributes['surfaces'], $profile)) {
                continue; // profile unchanged: keep the run idempotent
            } else {
                $attributes['surfaces'] = $profile;
            }

            // Whole jsonb rewrite: jsonb_set cannot target a JSON array.
            $this->db->executeStatement(
                'UPDATE recommended_route SET attributes = :attrs, updated_at = NOW() WHERE id = :id',
                ['attrs' => json_encode($attributes, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), 'id' => $row['id']],
            );
            ++$updated;
        }

        return $updated;
    }

    /**
     * Field-wise compare: jsonb does not preserve key order, so `===` is unreliable.
     *
     * @param array{covered: int, parts: list<array{surface: string, pct: int}>} $new
     */
    private static function sameProfile(mixed $old, array $new): bool
    {
        if (!\is_array($old) || ($old['covered'] ?? null) !== $new['covered']) {
            return false;
        }
        $oldParts = $old['parts'] ?? null;
        if (!\is_array($oldParts) || \count($oldParts) !== \count($new['parts'])) {
            return false;
        }
        foreach ($new['parts'] as $i => $part) {
            $oldPart = $oldParts[$i] ?? null;
            if (!\is_array($oldPart)
                || ($oldPart['surface'] ?? null) !== $part['surface']
                || ($oldPart['pct'] ?? null) !== $part['pct']) {
                return false;
            }
        }

        return true;
    }
}
