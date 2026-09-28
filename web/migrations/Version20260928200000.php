<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Each region's box, kept by the database beside its shape.
 *
 * RegionRegistryProvider::all() computed every region's box from `geom` on
 * each call: 109 MB of shapes read for 271 regions, 350 ms, on every /best and
 * /map page view (owner 2026-09-28: "every selection of filters takes about
 * 500ms"). The edges are now stored generated columns: Postgres computes them
 * when a shape is written and never again, and the registry reads four
 * numbers per region.
 *
 * Longitude is seam-aware, the same expression the registry used: a region
 * wider than 180 degrees crosses the antimeridian and is measured in the
 * shifted 0..360 space, so its box comes back west > east (RFC 7946 §5.2).
 *
 * @see docs/specs/map-and-search.md §4.5
 */
final class Version20260928200000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'region: the seam-aware box as stored generated columns';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $shifted = static fn (string $fn): string => "CASE WHEN {$fn}(ST_ShiftLongitude(geom)) > 180 THEN {$fn}(ST_ShiftLongitude(geom)) - 360 ELSE {$fn}(ST_ShiftLongitude(geom)) END";
        $seam = 'ST_XMax(geom) - ST_XMin(geom) > 180';
        $this->addSql("ALTER TABLE region
            ADD COLUMN bbox_w DOUBLE PRECISION GENERATED ALWAYS AS (CASE WHEN {$seam} THEN {$shifted('ST_XMin')} ELSE ST_XMin(geom) END) STORED,
            ADD COLUMN bbox_s DOUBLE PRECISION GENERATED ALWAYS AS (ST_YMin(geom)) STORED,
            ADD COLUMN bbox_e DOUBLE PRECISION GENERATED ALWAYS AS (CASE WHEN {$seam} THEN {$shifted('ST_XMax')} ELSE ST_XMax(geom) END) STORED,
            ADD COLUMN bbox_n DOUBLE PRECISION GENERATED ALWAYS AS (ST_YMax(geom)) STORED");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE region DROP COLUMN bbox_w, DROP COLUMN bbox_s, DROP COLUMN bbox_e, DROP COLUMN bbox_n');
    }
}
