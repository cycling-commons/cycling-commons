<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * heat_point.region_id (07-20 Phase 2 review finding 5): the ride-heat layer
 * filters client-side on the active scope like every other served layer, so
 * each heat point carries its region membership. Nullable — a point in no
 * region stays NULL and is hidden in any named-region/country scope, the same
 * leak-safe default every rid-less served row gets (map-and-search.md §4.5).
 *
 * Backfills existing rows with the identical smallest-area-wins rule
 * ImportCatalogCommand::recomputeMembership() applies on every import, so
 * already-imported databases are stamped without waiting for a re-import.
 */
final class Version20260721120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add heat_point.region_id (nullable) + backfill via smallest-area-wins ST_Contains';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE heat_point ADD region_id BIGINT DEFAULT NULL');
        $this->addSql(
            'UPDATE heat_point SET region_id = m.region_id FROM (
                SELECT DISTINCT ON (h.id) h.id AS heat_id, r.id AS region_id
                FROM heat_point h JOIN region r ON ST_Contains(r.geom, h.geom)
                ORDER BY h.id, r.area_km2 ASC NULLS LAST, r.id ASC
             ) m WHERE heat_point.id = m.heat_id',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE heat_point DROP region_id');
    }
}
