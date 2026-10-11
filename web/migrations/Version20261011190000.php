<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The spent proof-of-work challenges, in Postgres (docs/specs/contact-and-
 * support.md §3, audit 2026-10-04). A cache could only read, then write, so
 * two requests carrying the same solution at the same moment both passed. One
 * INSERT ... ON CONFLICT DO NOTHING on this primary key is the whole spend:
 * whichever request inserts first wins, on any web host. Rows live until the
 * challenge would have expired anyway.
 */
final class Version20261011190000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'pow_spent: one row per spent proof-of-work challenge, spent atomically';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pow_spent (challenge_hash CHAR(64) NOT NULL PRIMARY KEY, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL)');
        $this->addSql('CREATE INDEX idx_pow_spent_expires ON pow_spent (expires_at)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pow_spent');
    }
}
