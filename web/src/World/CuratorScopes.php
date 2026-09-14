<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\World;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The areas of a country somebody can ask for by name, because we hold one.
 *
 * **What this list is.** Divisions we hold a boundary for in `world_division`,
 * so a request for one can be acted on: onboarding it is promoting a row we
 * already have, not somebody drawing a shape. An area nobody holds a boundary
 * for is a different kind of request and does not belong in a picker that
 * implies we can go and add it (owner 2026-09-13: "User can only ask via this
 * form what we have. If they want something different we can't automate it").
 *
 * **It leaves out what is already onboarded, by ISO 3166-2 code.** Never by
 * name: `region` rows are named in English ("Bavaria", "Flanders") and the
 * boundary data in the country's own language ("Bayern", "Vlaanderen"), so a
 * name comparison matched none of them. Measured 2026-09-14, 61 live regions
 * were being offered again as "not on the Commons yet", and a curator could
 * have applied for Bayern while Bavaria was live: two people on the same
 * ground, which is what the one-level rule exists to prevent. Divisions with no
 * ISO code are left out for the same reason, since nothing could tell them
 * apart from a live region; in practice those are uninhabited (Coral Sea
 * Islands, Plazas de Soberanía).
 *
 * **Size is measured, not guessed.** A country whose divisions are too small to
 * be a scope offers only itself. The test is the median division area:
 *
 *     Slovenia     212 municipalities,  median    65 km²   country only
 *     Luxembourg    12 cantons,         median   218 km²   country only
 *     Switzerland   26 cantons,         median   883 km²   offered
 *     Netherlands   12 provinces,       median 3,132 km²   offered
 *
 * 500 km² sits in the gap, and it reproduces by measurement the call
 * `tools/divisions/config.py` had made by hand for Slovenia and Luxembourg
 * (owner 2026-09-14). The median rather than the mean, because one huge
 * territory (Nunavut, the Northern Territory) must not make a country of small
 * provinces look curatable, nor one tiny city-state district the reverse.
 *
 * An environment where the boundaries have not been loaded offers only
 * countries. That is the safe way to be empty.
 *
 * @see docs/specs/moderation-and-contribution.md §11.1a
 *
 * @api
 */
final class CuratorScopes
{
    /** Below this median division area a country's divisions are not scopes. */
    public const float MIN_MEDIAN_KM2 = 500.0;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * One country's askable areas, sorted by name. Empty means the country is the only scope.
     *
     * @return list<string>
     */
    public function forCountry(string $countryCode): array
    {
        return array_column($this->forCountries([$countryCode]), 'name');
    }

    /**
     * Every named country's askable areas, for the one page that needs them all.
     *
     * @param list<string> $countryCodes
     *
     * @return list<array{name: string, cc: string}>
     */
    public function forCountries(array $countryCodes): array
    {
        if ([] === $countryCodes) {
            return [];
        }

        /** @var list<array{name: string, cc: string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            "WITH sized AS (
                 SELECT country_code,
                        percentile_cont(0.5) WITHIN GROUP (ORDER BY area_km2) AS median_km2
                   FROM world_division
                  WHERE subtype = 'region' AND iso_code IS NOT NULL AND country_code IN (:codes)
                  GROUP BY country_code
             )
             SELECT DISTINCT w.name, w.country_code AS cc
               FROM world_division w
               JOIN sized s ON s.country_code = w.country_code
              WHERE w.subtype = 'region'
                AND w.iso_code IS NOT NULL
                AND s.median_km2 >= :min
                AND NOT EXISTS (SELECT 1 FROM region r WHERE r.iso_code = w.iso_code)
              ORDER BY w.country_code, w.name",
            [
                'codes' => array_map(static fn (string $c): string => strtoupper(trim($c)), $countryCodes),
                'min' => self::MIN_MEDIAN_KM2,
            ],
            ['codes' => ArrayParameterType::STRING],
        );

        return array_map(
            static fn (array $r): array => ['name' => (string) $r['name'], 'cc' => (string) $r['cc']],
            $rows,
        );
    }
}
