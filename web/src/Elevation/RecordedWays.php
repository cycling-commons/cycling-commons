<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Elevation;

/**
 * What OpenStreetMap records about the ways under a planned leg (docs/specs/route-planner.md section 4.4a), by way
 * id: the surface class, smoothness and tracktype, and the structure, `bridge` or `tunnel` from any value of that tag
 * but `no` (the engine calls only bridge=yes a bridge). Null when the leg starts outside every country whose ways are
 * loaded: the engine's own words stand.
 */
interface RecordedWays
{
    /**
     * @param list<int> $wayIds
     *
     * @return array<int, array{surface: string|null, smoothness: string|null, tracktype: string|null, structure?: string|null}>|null
     */
    public function forLeg(float $lat, float $lng, array $wayIds): ?array;
}
