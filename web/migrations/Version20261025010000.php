<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Catalog\RegionDerivations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The region pages' drawings leave the big lakes out (map-and-search.md §4.6).
 *
 * `region_land(geom)` is a shape minus the lakes the harvest keeps in the
 * pipeline-owned `coverage_water`: the shape as it is while that table does
 * not exist, touches no lake, or the cut fails, so a bad lake never stops a
 * derivation. `region_silhouettes_refresh()` draws every region again with
 * RegionDerivations::silhouetteSql(); the pipeline calls it after a harvest,
 * once it exists. The silhouettes are drawn again here, so a hole a region
 * already has (an enclave) shows from now on.
 */
final class Version20261025010000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'region_land() and region_silhouettes_refresh(): region drawings leave the big lakes out and keep their holes';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION region_land(g geometry) RETURNS geometry
            LANGUAGE plpgsql STABLE AS $fn$
            DECLARE
                lakes geometry;
            BEGIN
                IF g IS NULL OR to_regclass('public.coverage_water') IS NULL THEN
                    RETURN g;
                END IF;
                EXECUTE 'SELECT ST_Union(w.geom) FROM coverage_water w WHERE w.geom && $1 AND ST_Intersects(w.geom, $1)'
                    INTO lakes USING g;
                IF lakes IS NULL THEN
                    RETURN g;
                END IF;
                RETURN ST_Difference(g, lakes);
            EXCEPTION WHEN OTHERS THEN
                RETURN g;
            END $fn$
            SQL);
        $this->addSql("COMMENT ON FUNCTION region_land(geometry) IS 'A region shape minus the big lakes in coverage_water, for region.silhouette only; the shape as it is without the table, without a lake, or when the cut fails.'");
        $this->addSql(self::refreshFunction());
        $this->addSql("COMMENT ON FUNCTION region_silhouettes_refresh() IS 'Draws region.silhouette again (RegionDerivations::silhouetteSql()); called by the coverage pipeline after it loads lakes.'");
        $this->addSql(RegionDerivations::silhouetteSql());
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP FUNCTION IF EXISTS region_silhouettes_refresh()');
        // region_land() stays: RegionDerivations::silhouetteSql() calls it.
    }

    /** The refresh function, its body the silhouette SQL as it is now (RegionSilhouettesFunctionTest compares the two). */
    public static function refreshFunction(): string
    {
        return "CREATE OR REPLACE FUNCTION region_silhouettes_refresh() RETURNS void\n"
            ."LANGUAGE plpgsql AS \$fn\$\nBEGIN\n".RegionDerivations::silhouetteSql().";\nEND \$fn\$";
    }
}
