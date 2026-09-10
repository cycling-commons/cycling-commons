<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution\Gpx;

/**
 * Parsed GPX track: [lat, lng, ele|null]. GeoJSON storage flips to [lng, lat].
 *
 * @api
 */
final readonly class GpxTrack
{
    /** @param list<array{0: float, 1: float, 2: float|null}> $points */
    public function __construct(
        public array $points,
    ) {
    }
}
