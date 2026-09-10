<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `item.osm_checked_at`, and the one backfill that needs no judgement.
 *
 * @see docs/specs/catalog-data-model.md §5b
 */
final class Version20260825110000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'item.osm_checked_at: tell "no OSM counterpart" apart from "nobody asked"';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item ADD osm_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN item.osm_checked_at IS 'When the OSM question was answered. NULL = nobody asked. Set with osm_ref NULL = asked, and this place is not in OSM. catalog-data-model.md §5b'");

        // An osm-sourced row whose source_ref IS an OSM object records that
        // object by construction. The ref was already in the row, in a column
        // nothing joins on. 775 rows when this migration was written.
        $this->addSql("
            UPDATE item
               SET osm_ref = source_ref, osm_checked_at = NOW()
             WHERE source = 'osm'
               AND source_ref ~ '^(node|way)/[0-9]+$'
               AND osm_ref IS NULL
        ");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // Deliberately leaves osm_ref alone: the values the backfill wrote are
        // correct whatever this column does.
        $this->addSql('ALTER TABLE item DROP osm_checked_at');
    }
}
