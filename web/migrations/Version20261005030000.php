<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * traffic_cell and traffic_rider lose way_key: nothing read it, and in a stolen
 * copy it linked every row of one road (riders per road, a heavy rider's row
 * size). A row now holds only its own HMAC key and a sealed payload.
 *
 * @see docs/specs/traffic-measurements.md §4.3
 */
final class Version20261005030000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'traffic_cell, traffic_rider: drop way_key, which linked the rows of one road';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX traffic_cell_way_key_idx');
        $this->addSql('DROP INDEX traffic_rider_way_key_idx');
        $this->addSql('ALTER TABLE traffic_cell DROP way_key');
        $this->addSql('ALTER TABLE traffic_rider DROP way_key');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE traffic_cell ADD way_key BYTEA DEFAULT ''::bytea NOT NULL");
        $this->addSql("ALTER TABLE traffic_rider ADD way_key BYTEA DEFAULT ''::bytea NOT NULL");
        $this->addSql('CREATE INDEX traffic_cell_way_key_idx ON traffic_cell (way_key)');
        $this->addSql('CREATE INDEX traffic_rider_way_key_idx ON traffic_rider (way_key)');
    }
}
