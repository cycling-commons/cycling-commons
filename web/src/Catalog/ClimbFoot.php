<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * A climb's point is the foot of its line (owner 2026-09-30).
 *
 * A climb (letter N) is a line from foot to summit in `attributes.route`, as
 * [lat, lng] pairs. Its point, `item.geom`, is `route[0]` whenever the line is
 * usable: an array of at least two entries whose first entry is a numeric
 * pair. The database keeps that on every write (the `item_climb_at_foot`
 * trigger, `climb_foot(attributes)` in SQL); this is the same rule for a
 * writer that measures from the point before it writes, such as a seed asking
 * which region the climb is in or whether the catalog already holds it.
 *
 * @see docs/specs/edit-items/N-climbs.md
 * @see docs/specs/catalog-data-model.md §6
 */
final class ClimbFoot
{
    /**
     * [lat, lng] of the foot, or null when $route is not a usable line.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function of(mixed $route): ?array
    {
        if (!\is_array($route) || !array_is_list($route) || \count($route) < 2) {
            return null;
        }
        $foot = $route[0];
        if (!\is_array($foot) || !\array_key_exists(0, $foot) || !\array_key_exists(1, $foot)
            || !(\is_int($foot[0]) || \is_float($foot[0])) || !(\is_int($foot[1]) || \is_float($foot[1]))) {
            return null;
        }

        return [(float) $foot[0], (float) $foot[1]];
    }
}
