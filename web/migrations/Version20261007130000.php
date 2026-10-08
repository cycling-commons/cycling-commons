<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Traffic without riders (traffic-measurements.md §4.3): lines wait,
 * encrypted, in `traffic_pool` until their block has enough of them, then go
 * into the plain sums of `traffic_total`. The encrypted cells go; they held
 * nothing yet that the new shape could read.
 */
final class Version20261007130000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'traffic: waiting room (traffic_pool) and plain totals (traffic_total) replace traffic_cell';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE traffic_cell');
        $this->addSql('DELETE FROM traffic_seen');
        $this->addSql('CREATE TABLE traffic_pool (id BYTEA NOT NULL, block_key BYTEA NOT NULL, payload BYTEA NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX traffic_pool_block_key_idx ON traffic_pool (block_key)');
        $this->addSql("CREATE TABLE traffic_total (
            way BIGINT NOT NULL, dir CHAR(1) NOT NULL, band SMALLINT NOT NULL, day_type VARCHAR(8) NOT NULL, quarter CHAR(7) NOT NULL,
            label CHAR(1) NOT NULL, region INT DEFAULT NULL,
            distance_m BIGINT NOT NULL DEFAULT 0, time_s BIGINT NOT NULL DEFAULT 0, passes INT NOT NULL DEFAULT 0, nearby INT NOT NULL DEFAULT 0,
            speed_sum DOUBLE PRECISION NOT NULL DEFAULT 0, speed_passes INT NOT NULL DEFAULT 0, bins JSONB NOT NULL DEFAULT '[]',
            lines INT NOT NULL DEFAULT 0, days INT NOT NULL DEFAULT 0,
            PRIMARY KEY (way, dir, band, day_type, quarter))");
        $this->addSql("COMMENT ON TABLE traffic_pool IS 'Traffic lines waiting until their block (road, direction, part of day, day type) has enough of them. Encrypted; no account, no time column.'");
        $this->addSql("COMMENT ON TABLE traffic_total IS 'Plain traffic sums per road, direction, part of day, day type and quarter, from lines that left the waiting room together. No rider.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE traffic_total');
        $this->addSql('DROP TABLE traffic_pool');
        $this->addSql('CREATE TABLE traffic_cell (bucket_key BYTEA NOT NULL, payload BYTEA NOT NULL, PRIMARY KEY (bucket_key))');
    }
}
