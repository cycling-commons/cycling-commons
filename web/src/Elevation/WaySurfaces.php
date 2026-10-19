<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Elevation;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * What OpenStreetMap records about the ways under a planned leg (docs/specs/route-planner.md section 4.4a): the
 * surface class, the smoothness and the tracktype, by way id, from `coverage_way_surface`. The weekly surface
 * pipeline fills that table per onboarded country and marks each loaded country in `coverage_way_surface_country`
 * (docs/specs/coverage-provider.md section 4). A way of a loaded country that the table does not hold has none of
 * the three recorded.
 *
 * Null when the leg starts outside a loaded country, or the tables are not there yet: the engine's own surface
 * words stand then.
 *
 * @api
 */
final class WaySurfaces implements RecordedWays
{
    /** @var list<string>|null the loaded countries, read once per request */
    private ?array $loaded = null;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param list<int> $wayIds the leg's ways, from the engine's `edge.way_id`
     *
     * @return array<int, array{surface: string|null, smoothness: string|null, tracktype: string|null}>|null by way id; a
     *                                                                                                         way with nothing recorded is absent
     */
    #[\Override]
    public function forLeg(float $lat, float $lng, array $wayIds): ?array
    {
        $loaded = $this->loaded();
        if ([] === $loaded) {
            return null;
        }
        $cc = $this->db->fetchOne(
            'SELECT country_code FROM region WHERE ST_Contains(geom, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)) LIMIT 1',
            ['lng' => $lng, 'lat' => $lat],
        );
        if (!\is_string($cc) || !\in_array(strtoupper($cc), $loaded, true)) {
            return null;
        }
        $ids = array_values(array_unique(array_filter($wayIds, static fn (int $id): bool => $id > 0)));
        if ([] === $ids) {
            return [];
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT way_id, surface, smoothness, tracktype FROM coverage_way_surface WHERE way_id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['way_id']] = [
                'surface' => \is_string($row['surface']) ? $row['surface'] : null,
                'smoothness' => \is_string($row['smoothness']) ? $row['smoothness'] : null,
                'tracktype' => \is_string($row['tracktype']) ? $row['tracktype'] : null,
            ];
        }

        return $out;
    }

    /**
     * The countries whose ways are loaded. to_regclass, not the schema manager: the pipeline owns the tables and
     * schema_filter hides `coverage_*` from Doctrine.
     *
     * @return list<string>
     */
    private function loaded(): array
    {
        if (null !== $this->loaded) {
            return $this->loaded;
        }
        if (null === $this->db->fetchOne("SELECT to_regclass('public.coverage_way_surface_country')")) {
            return $this->loaded = [];
        }

        return $this->loaded = array_map(
            static fn (mixed $cc): string => strtoupper(\is_scalar($cc) ? (string) $cc : ''),
            $this->db->fetchFirstColumn('SELECT country_code FROM coverage_way_surface_country'),
        );
    }
}
