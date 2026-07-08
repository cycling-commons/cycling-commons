<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Contribution\Gpx;

/**
 * A parsed GPX track. Points are [lat, lng, ele|null] triples — NOTE the
 * [lat, lng] order (GeoJSON storage flips to [lng, lat] at persist time).
 *
 * @api Immutable result of GpxParser::parse(); its $points feed TrackProcessor.
 */
final readonly class GpxTrack
{
    /** @param list<array{0: float, 1: float, 2: float|null}> $points */
    public function __construct(
        public array $points,
    ) {
    }
}
