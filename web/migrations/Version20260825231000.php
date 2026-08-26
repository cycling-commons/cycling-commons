<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `item.osm_candidates` + `item.osm_candidates_at`: the OSM-candidate list a
 * queue card shows is computed once and stored, not asked of two million
 * coverage rows on every list view (owner 2026-08-25).
 *
 * @see docs/specs/catalog-data-model.md §5b
 */
final class Version20260825231000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'item.osm_candidates(_at): store the OSM candidate list once; the harvest clears it';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item ADD osm_candidates JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE item ADD osm_candidates_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN item.osm_candidates IS 'OsmLinker::nearby() for this row, stored (list of {ref,name,distanceM}). NULL = not computed yet, or cleared by a coverage harvest of this country. catalog-data-model.md §5b'");
        $this->addSql("COMMENT ON COLUMN item.osm_candidates_at IS 'When osm_candidates was computed. NULL with osm_candidates NULL = compute on next read.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item DROP osm_candidates_at');
        $this->addSql('ALTER TABLE item DROP osm_candidates');
    }
}
