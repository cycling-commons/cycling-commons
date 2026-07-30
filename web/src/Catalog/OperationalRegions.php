<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * The operational-region rule (2026-07-30-dynamic-region-pages-design.md §4):
 * a region row is OPERATIONAL iff its admin_level equals the deepest onboarded
 * level for its country. The level-2 country outlines the 2+4 playbook emits
 * are infrastructure — submission anchoring and the country polygon — and must
 * never surface as scope-rail chips, moderation atoms or page entries. Derived,
 * never stored: a country that later onboards a deeper level demotes the old
 * one the day the rows land.
 *
 * IS NOT DISTINCT FROM keeps single-row countries with NULL admin_level
 * operational (legacy fixtures; MAX over only-NULL is NULL).
 *
 * Exemptions (same spec, §4): RegionResolver and the importer's membership
 * recompute stay UNFILTERED — containment with the smallest-area tie-break is
 * how evidence anchors in not-yet-subdivided countries.
 *
 * @api Consumed by RegionRegistryProvider, RegionDirectoryProvider,
 *      JoinCountryController, ModerateRegionsController.
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
