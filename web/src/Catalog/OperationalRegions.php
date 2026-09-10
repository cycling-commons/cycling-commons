<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * A region is operational iff its admin_level equals the deepest onboarded level for its country. Level-2 country outlines must never surface as scope chips. Derived, never stored. Membership writers (RegionResolver, import recompute) stay unfiltered.
 *
 * @see docs/specs/catalog-data-model.md §2.4
 *
 * @api
 */
final class OperationalRegions
{
    public static function predicate(string $alias = 'region'): string
    {
        return sprintf(
            '%1$s.admin_level IS NOT DISTINCT FROM'
            .' (SELECT MAX(r2.admin_level) FROM region r2 WHERE r2.country_code = %1$s.country_code)',
            $alias,
        );
    }
}
