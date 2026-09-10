<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * item_confirmation.source — where a rider's stance came from.
 *
 * `drawer` is somebody answering the map's question; `form` is the person who
 * ADDED the place answering the same question on the improve form. The second
 * is kept so the map never asks them again, and excluded from the tally and
 * the verified derivation, because it is the claim rather than a confirmation
 * of it (a single counted row turns a dot into a verified pin, so counting it
 * would let anyone verify their own contribution).
 *
 * Every existing row is a drawer answer — the form path did not exist — so the
 * default backfills them correctly.
 */
final class Version20260802100000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'item_confirmation.source (drawer|form): keep a submitter\'s own form answer without counting it';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE item_confirmation ADD COLUMN source VARCHAR(8) NOT NULL DEFAULT 'drawer'");
        // The tally and the verified derivation both filter on it.
        $this->addSql('CREATE INDEX idx_item_confirmation_source ON item_confirmation (item_id, source)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_item_confirmation_source');
        $this->addSql('ALTER TABLE item_confirmation DROP COLUMN source');
    }
}
