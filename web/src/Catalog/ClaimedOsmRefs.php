<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * Which OSM refs a served item claims, so the coverage point with that ref is not drawn or listed a second time. One definition, shared by the map payload's tile dedupe and the ride check's coverage arm. Do not fork the SQL.
 *
 * A served item claims a ref through any of:
 * - its `source_ref`, when the row was materialized from OSM and is not an untouched import the coverage cache now serves (CoverageRetirement);
 * - every way a served segment spans (`attributes.waysSpanned`);
 * - its `osm_ref`, the OSM twin of an authority row attached to a tap (data-provider-hierarchy.md §4.1).
 *
 * A row reported gone still claims its ref, so its twin stays hidden too (GoneRows).
 *
 * @see docs/specs/coverage-provider.md §6
 * @see docs/specs/osm-data-architecture.md §8
 */
final class ClaimedOsmRefs
{
    /**
     * A SELECT yielding one non-null `ref` column per claimed ref (duplicates removed).
     */
    public static function selectSql(): string
    {
        $served = 'i.state IN '.ItemState::servedSqlTuple();
        $sourced = "SELECT i.source_ref AS ref FROM item i WHERE i.source = 'osm' AND ".$served
            .' AND NOT (i.letter IN '.CoverageRetirement::lettersSqlTuple()
            .' AND '.CoverageRetirement::untouchedOsmSql('i').')';
        // jsonb_exists(), not `?`: DBAL treats `?` as a placeholder.
        $spanned = "SELECT jsonb_array_elements_text(i.attributes->'waysSpanned') AS ref"
            ." FROM item i WHERE jsonb_exists(i.attributes, 'waysSpanned') AND ".$served;
        $twins = 'SELECT i.osm_ref AS ref FROM item i WHERE i.osm_ref IS NOT NULL AND '.$served;

        return 'SELECT ref FROM ('.$sourced.' UNION '.$spanned.' UNION '.$twins.') AS claimed WHERE ref IS NOT NULL';
    }

    /**
     * TRUE when `$refExpr` (an SQL expression, e.g. `cp.ref`) is claimed by a served item.
     */
    public static function claimedSql(string $refExpr): string
    {
        return $refExpr.' IN ('.self::selectSql().')';
    }
}
