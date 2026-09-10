<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * Rider-facing kind of way for the A form. OSM `highway=` is many-to-one here; `unclassified` is not offered as a choice.
 *
 * @see docs/specs/edit-items/A-road-surface.md
 *
 * @api
 */
final class RoadType
{
    /** @var list<string> The vocabulary the A form offers, coarse to fine. */
    public const array DECLARABLE = [
        'Main road', 'Local road', 'Residential street', 'Farm or forest track', 'Path or trail', 'Cycleway',
    ];

    /** @var array<string, string> OSM `highway` value → the rider-facing kind it becomes. */
    public const array FROM_HIGHWAY = [
        'primary' => 'Main road',
        'primary_link' => 'Main road',
        'secondary' => 'Main road',
        'secondary_link' => 'Main road',
        'tertiary' => 'Local road',
        'tertiary_link' => 'Local road',
        'unclassified' => 'Local road',
        'road' => 'Local road',
        'residential' => 'Residential street',
        'living_street' => 'Residential street',
        'track' => 'Farm or forest track',
        'path' => 'Path or trail',
        'footway' => 'Path or trail',
        'bridleway' => 'Path or trail',
        'cycleway' => 'Cycleway',
    ];

    /**
     * Null rather than a fallback: an unmapped OSM value must not be presented as a fact.
     */
    public static function fromHighway(string $highway): ?string
    {
        return self::FROM_HIGHWAY[$highway] ?? null;
    }
}
