<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Felt safety, marked by hand (traffic-measurements.md §10): a mark waits,
 * encrypted, in `safety_pool` until its block (road, part of the day, day
 * type) has enough of them, then goes into the plain counts of
 * `safety_total`. No account and no time column in either.
 */
final class Version20261019010000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'felt safety: waiting room (safety_pool) and plain counts (safety_total)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE safety_pool (id BYTEA NOT NULL, block_key BYTEA NOT NULL, payload BYTEA NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX safety_pool_block_key_idx ON safety_pool (block_key)');
        $this->addSql('CREATE TABLE safety_total (
            way BIGINT NOT NULL, band SMALLINT NOT NULL, day_type VARCHAR(8) NOT NULL, quarter CHAR(7) NOT NULL,
            label CHAR(1) NOT NULL, region INT DEFAULT NULL,
            safe BIGINT NOT NULL DEFAULT 0, unsafe BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (way, band, day_type, quarter))');
        $this->addSql("COMMENT ON TABLE safety_pool IS 'Felt-safety marks waiting until their block (road, part of day, day type) has enough of them. Encrypted; no account, no time column.'");
        $this->addSql("COMMENT ON TABLE safety_total IS 'Plain counts of safe and unsafe marks per road, part of day, day type and quarter, from marks that left the waiting room together. No rider.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE safety_total');
        $this->addSql('DROP TABLE safety_pool');
    }
}
