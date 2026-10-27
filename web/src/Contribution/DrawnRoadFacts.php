<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

use App\Catalog\RoadType;
use App\Catalog\SurfaceVocabulary;
use Doctrine\DBAL\Connection;

/**
 * What OpenStreetMap says about a road drawn in the add form (edit-items/A-road-surface.md, "The form reads the
 * road it follows"): the ways under the line, the longest first, and the name, road type and surface of the longest,
 * in the form's own values, so the form can fill what the rider left empty. The surface is the class our road
 * pieces hold for that way (`coverage_way_surface`), the one the map draws; never the routing engine's grade, which
 * guesses a surface for a way OpenStreetMap records none for. A way newer than our last build fills no surface.
 *
 * @api
 */
final readonly class DrawnRoadFacts
{
    /** The most ways the form names under the map. */
    public const int MAX_SHOWN = 8;

    public function __construct(
        private RoadWayLocator $roads,
        private Connection $db,
    ) {
    }

    /**
     * @param list<array{0: float, 1: float}> $line [lng, lat] pairs
     *
     * @return array{ways: list<array{ref: string, name: ?string, metres: int}>, name: ?string, roadType: ?string, surface: ?string}
     */
    public function of(array $line): array
    {
        $roads = $this->roads->roadsAlong($line);
        $main = $roads[0] ?? null;
        $surface = null;
        // to_regclass: the pipeline owns the table, and a stack it has not filled yet has none.
        if (null !== $main && null !== $this->db->fetchOne("SELECT to_regclass('public.coverage_way_surface')")) {
            $cls = $this->db->fetchOne(
                'SELECT surface FROM coverage_way_surface WHERE way_id = :id',
                ['id' => (int) substr($main['ref'], 4)],
            );
            $surface = \is_string($cls) ? SurfaceVocabulary::fromTileClass($cls) : null;
        }

        return [
            'ways' => array_map(
                static fn (array $r): array => ['ref' => $r['ref'], 'name' => $r['name'], 'metres' => $r['metres']],
                \array_slice($roads, 0, self::MAX_SHOWN),
            ),
            'name' => $main['name'] ?? null,
            'roadType' => null !== ($main['highway'] ?? null) ? RoadType::fromHighway($main['highway']) : null,
            'surface' => $surface,
        ];
    }
}
