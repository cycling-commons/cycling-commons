<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Traffic storage without a row per rider: `traffic_rider` goes, and a cell
 * keeps anonymous rider voices instead (traffic-measurements.md §3.5, §4.3).
 *
 * The cells and dedupe codes are emptied with it: their time key and payload
 * have a new shape, and a code that stays claimed would refuse the same ride
 * sent again in that shape. Production never stored traffic before this.
 */
final class Version20261007120000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'traffic: drop traffic_rider; empty traffic_cell and traffic_seen for the new shape';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE traffic_rider');
        $this->addSql('DELETE FROM traffic_cell');
        $this->addSql('DELETE FROM traffic_seen');
        $this->addSql("COMMENT ON TABLE traffic_cell IS 'Radar traffic totals per road piece, direction and time key, with anonymous rider voices and day codes. Encrypted; holds no account.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE traffic_rider (rider_key BYTEA NOT NULL, payload BYTEA NOT NULL, PRIMARY KEY (rider_key))');
    }
}
