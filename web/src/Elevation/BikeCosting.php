<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Elevation;

use App\Catalog\BikeType;

/**
 * The one place a Commons bike type becomes the routing engine's `bicycle`
 * costing options (docs/specs/route-planner.md section 4.2).
 *
 * The engine knows four bicycle types (Road, Hybrid, Cross, Mountain) and
 * silently rides its default on a value it does not know, which moves a
 * route by a few per cent without a word. So the mapping is a closed match
 * over the enum: every type has a block, and a value outside the enum never
 * reaches the engine. Whatever the options, the engine rides only roads open
 * to bicycles; they weigh roads a cyclist may use, never roads they may not.
 *
 * @api
 */
final class BikeCosting
{
    /** @return array<string, float|string> */
    public static function options(BikeType $bike): array
    {
        return match ($bike) {
            BikeType::Road => ['bicycle_type' => 'Road'],
            BikeType::Gravel => ['bicycle_type' => 'Cross'],
            BikeType::Mtb => ['bicycle_type' => 'Mountain'],
            // A motor does not mind a hill; hybrid surfaces (rough paving) are fine.
            BikeType::Ebike => ['bicycle_type' => 'Hybrid', 'use_hills' => 1.0],
            // Cannot stand on a climb or absorb a rutted track.
            BikeType::Handbike => ['bicycle_type' => 'Road', 'use_hills' => 0.0, 'avoid_bad_surfaces' => 1.0],
            // Long or wide: steep and rough roads cost more.
            BikeType::Recumbent, BikeType::Trike, BikeType::Tandem => ['bicycle_type' => 'Road', 'use_hills' => 0.2, 'avoid_bad_surfaces' => 0.8],
        };
    }
}
