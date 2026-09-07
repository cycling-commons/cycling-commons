<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * A row reported "Not there anymore" is served nowhere (payload, public API, coverage search and nearby), while its OSM ref stays claimed so the coverage twin stays hidden too. One predicate, shared. Do not fork the SQL.
 *
 * @see docs/specs/catalog-data-model.md §7
 *
 * @api
 */
final class GoneRows
{
    public const string CONDITION = 'Not there anymore';

    /** TRUE for a row still on the map. */
    public static function notGoneSql(string $alias): string
    {
        return sprintf("COALESCE(%s.attributes->>'condition', '') <> '%s'", $alias, self::CONDITION);
    }
}
