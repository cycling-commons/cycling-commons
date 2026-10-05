<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Onboarding;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Re-derives the region data written at contribution time, for a set of countries.
 *
 * Submissions keep the region and country SpatialResolver stamped when they
 * were made, and an item keeps its country, so a newly seeded country leaves
 * rider data region-less or under the wrong country. Smallest containing
 * region wins, no snap: SpatialResolver's rule, set-based.
 *
 * @api
 */
final class Restamper
{
    private const string SUBMISSION_SQL = <<<'SQL'
        WITH m AS (
            SELECT DISTINCT ON (s.id) s.id, r.id AS region_id, r.country_code
            FROM submission s
            JOIN region r ON ST_Contains(r.geom, ST_PointOnSurface(s.geom))
            WHERE s.region_id IS NULL
               OR s.region_id IN (SELECT id FROM region WHERE country_code IN (:ccs))
            ORDER BY s.id, r.area_km2 ASC NULLS LAST, r.id ASC
        )
        UPDATE submission s SET region_id = m.region_id, country_code = m.country_code
        FROM m
        WHERE s.id = m.id
          AND m.country_code IN (:ccs)
          AND (s.region_id IS DISTINCT FROM m.region_id OR s.country_code IS DISTINCT FROM m.country_code)
        RETURNING m.country_code
        SQL;

    private const string ITEM_SQL = <<<'SQL'
        WITH m AS (
            SELECT DISTINCT ON (i.id) i.id, r.country_code
            FROM item i
            JOIN region r ON ST_Contains(r.geom, ST_PointOnSurface(i.geom))
            WHERE r.country_code <> ''
              AND (i.country_code IN (:ccs)
                   OR EXISTS (SELECT 1 FROM region rl
                               WHERE rl.country_code IN (:ccs)
                                 AND ST_Contains(rl.geom, ST_PointOnSurface(i.geom))))
            ORDER BY i.id, r.area_km2 ASC NULLS LAST, r.id ASC
        )
        UPDATE item i SET country_code = m.country_code
        FROM m
        WHERE i.id = m.id
          AND i.country_code IS DISTINCT FROM m.country_code
          AND (m.country_code IN (:ccs) OR i.country_code IN (:ccs))
        RETURNING m.country_code
        SQL;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param list<string> $countries ISO 3166-1 alpha-2, upper case
     *
     * @return array{submission: array<string, int>, item: array<string, int>} rows changed, per new country
     */
    public function restamp(array $countries, bool $dryRun): array
    {
        if ([] === $countries) {
            return ['submission' => [], 'item' => []];
        }
        if (!$dryRun) {
            return $this->run($countries);
        }
        $this->db->beginTransaction();
        try {
            return $this->run($countries);
        } finally {
            $this->db->rollBack();
        }
    }

    /**
     * @param list<string> $countries
     *
     * @return array{submission: array<string, int>, item: array<string, int>}
     */
    private function run(array $countries): array
    {
        $params = ['ccs' => $countries];
        $types = ['ccs' => ArrayParameterType::STRING];

        return [
            'submission' => self::tally($this->db->fetchFirstColumn(self::SUBMISSION_SQL, $params, $types)),
            'item' => self::tally($this->db->fetchFirstColumn(self::ITEM_SQL, $params, $types)),
        ];
    }

    /**
     * @param list<mixed> $codes
     *
     * @return array<string, int>
     */
    private static function tally(array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            $cc = trim((string) $code);
            $out[$cc] = ($out[$cc] ?? 0) + 1;
        }
        ksort($out);

        return $out;
    }
}
