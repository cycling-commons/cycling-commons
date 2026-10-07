<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Traffic measurements: encrypted running totals per road piece and time key,
 * one encrypted row per rider per road piece, and the dedupe codes.
 *
 * Every column is a keyed hash or an AES-GCM blob, and no table has a time
 * column: a write time would say when somebody rode.
 *
 * @see docs/specs/traffic-measurements.md §4.3
 */
final class Version20261005020000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'traffic_cell, traffic_rider, traffic_seen: encrypted radar traffic totals';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE traffic_cell (bucket_key BYTEA NOT NULL, way_key BYTEA NOT NULL, payload BYTEA NOT NULL, PRIMARY KEY (bucket_key))');
        $this->addSql('CREATE INDEX traffic_cell_way_key_idx ON traffic_cell (way_key)');
        $this->addSql('CREATE TABLE traffic_rider (rider_key BYTEA NOT NULL, way_key BYTEA NOT NULL, payload BYTEA NOT NULL, PRIMARY KEY (rider_key))');
        $this->addSql('CREATE INDEX traffic_rider_way_key_idx ON traffic_rider (way_key)');
        $this->addSql('CREATE TABLE traffic_seen (code BYTEA NOT NULL, PRIMARY KEY (code))');
        $this->addSql("COMMENT ON TABLE traffic_cell IS 'Running radar traffic totals per road piece, direction and time key. Encrypted; holds no rider.'");
        $this->addSql("COMMENT ON TABLE traffic_rider IS 'Per rider and road piece: distance and days per time key, for the disclosure rules. Encrypted; keyed by an HMAC of rider and road.'");
        $this->addSql("COMMENT ON TABLE traffic_seen IS 'HMACs of the dedupe codes already received, so no ride is counted twice.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE traffic_seen');
        $this->addSql('DROP TABLE traffic_rider');
        $this->addSql('DROP TABLE traffic_cell');
    }
}
