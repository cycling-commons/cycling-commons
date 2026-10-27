<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

/**
 * The OpenStreetMap ways a line drawn on the map follows (edit-items/A-road-surface.md, "A drawn road links
 * itself"): a road surface drawn from start to end is linked to the roads under it, the way it covers longest
 * first, so its OSM question is answered by construction.
 */
interface RoadWayLocator
{
    /**
     * @param list<array{0: float, 1: float}> $line [lng, lat] pairs, the drawn road
     *
     * @return list<string> `way/<id>` refs, the longest stretch first; empty when no engine answers
     */
    public function waysAlong(array $line): array;

    /**
     * The same ways with what the engine knows of each: its name and its OSM `highway` kind, where it maps to one.
     *
     * @param list<array{0: float, 1: float}> $line [lng, lat] pairs, the drawn road
     *
     * @return list<array{ref: string, metres: int, name: ?string, highway: ?string}> the longest stretch first
     */
    public function roadsAlong(array $line): array;
}
