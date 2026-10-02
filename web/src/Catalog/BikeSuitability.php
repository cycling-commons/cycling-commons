<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * The specialty-bike gate of route-domain.md §8.3, written once: a route
 * counts for a specialty bike only when its `attributes.bikeTypes` declares
 * that bike. Casting a vote, the ballot's candidates, the season results and
 * the map's best-of all use this condition, so the four cannot disagree on
 * which route suits which bike.
 *
 * @see docs/specs/route-domain.md §8.3
 *
 * @api
 */
final class BikeSuitability
{
    /**
     * SQL: the route aliased `$route` declares the bike bound to `:$param`
     * (JSONB containment). Apply it to a specialty bike
     * ({@see BikeType::isSpecialty()}) only: a general bike is not gated.
     */
    public static function declares(string $route, string $param): string
    {
        return "$route.attributes -> 'bikeTypes' @> to_jsonb(CAST(:$param AS text))";
    }
}
