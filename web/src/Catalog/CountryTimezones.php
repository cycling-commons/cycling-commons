<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

/**
 * IANA timezone => country, for the map's anonymous cold-start home-country hint.
 *
 * The zones stored in country.timezones of each seeded or live country. The
 * map still checks the country has regions (docs/specs/map-and-search.md §4.5).
 *
 * @api
 */
final class CountryTimezones
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string, string> */
    public function map(): array
    {
        $map = [];
        foreach ($this->db->fetchAllAssociative(
            "SELECT code, array_to_json(timezones)::text AS zones FROM country WHERE status IN ('seeded', 'live') ORDER BY code",
        ) as $row) {
            $zones = json_decode((string) $row['zones'], true);
            foreach (\is_array($zones) ? $zones : [] as $zone) {
                if (\is_string($zone) && '' !== $zone) {
                    $map[$zone] = trim((string) $row['code']);
                }
            }
        }

        return $map;
    }
}
