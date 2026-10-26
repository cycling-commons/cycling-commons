<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Elevation;

/**
 * The road surfaces our curators and riders set (layer A) close to a planned leg (docs/specs/route-planner.md section
 * 4.4a): each one's line, its surface label and its smoothness label, as the road surface form stores them.
 */
interface CuratedSurfaces
{
    /**
     * @param list<array{0: float, 1: float}> $coords [lat, lng] pairs, the leg's line
     *
     * @return list<array{line: list<array{0: float, 1: float}>, surface: string|null, smoothness: string|null}> lines as [lat, lng] pairs
     */
    public function near(array $coords): array;
}
