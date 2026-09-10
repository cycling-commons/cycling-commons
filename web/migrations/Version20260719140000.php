<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * region: iso_code / admin_level / source / active_cap — the Phase 1
 * foundation-hardening columns the region-scoping design needs before region
 * rows may multiply beyond the Wallonia seed (map-and-search.md §4.5 and
 * §7 "Phase 1"). All nullable:
 *  - iso_code joins the World bundle's Subdivision.code (e.g. BE-WAL);
 *  - admin_level / source carry import provenance (osm|overture, level 4…);
 *  - active_cap overrides route.region_active_cap per region (map-and-search.md §4.5).
 *
 * The coverage_poi.country_code index (map-and-search.md §4.5 "New index")
 * is deliberately NOT added here: coverage_poi is a pipeline-owned disposable
 * cache created outside Doctrine (pipeline/coverage/load.py ensure_schema), so
 * its index lands pipeline-side.
 */
final class Version20260719140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add region.iso_code / admin_level / source / active_cap (all nullable)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE region ADD iso_code VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE region ADD admin_level SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE region ADD source VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE region ADD active_cap SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE region DROP iso_code');
        $this->addSql('ALTER TABLE region DROP admin_level');
        $this->addSql('ALTER TABLE region DROP source');
        $this->addSql('ALTER TABLE region DROP active_cap');
    }
}
