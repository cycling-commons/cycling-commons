<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Vote;

/**
 * Which half of the world a region's seasons follow. South of the equator
 * spring starts in September.
 *
 * @see docs/specs/route-domain.md §8d
 *
 * @api
 */
enum Hemisphere: string
{
    case North = 'N';
    case South = 'S';

    /** A region's middle latitude decides; the equator itself counts as north. */
    public static function ofLatitude(float $lat): self
    {
        return $lat < 0.0 ? self::South : self::North;
    }
}
