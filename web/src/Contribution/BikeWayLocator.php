<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

/**
 * How far a point is from the nearest way a bike may ride.
 *
 * @see docs/specs/scenic-views.md
 */
interface BikeWayLocator
{
    public function nearest(float $lat, float $lng): BikeWayReading;
}
