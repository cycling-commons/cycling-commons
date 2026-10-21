<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * How long a Safe Browsing answer may be used (catalog-data-model.md §7):
 * a match no longer than the `cacheDuration` Google returns and never more
 * than 30 minutes (the Safe Browsing terms), a clean answer 10 minutes, an
 * unknown one not at all. Rows from before have no limit recorded and count
 * as expired, so they are asked again when next needed.
 */
final class Version20261020020000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'link_verdict.fresh_until: how long a Safe Browsing answer may be reused';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE link_verdict ADD fresh_until TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN link_verdict.fresh_until IS 'Until when the verdict may be used without asking Google again: unsafe at most 30 min (cacheDuration), safe 10 min, unknown never. NULL = expired.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE link_verdict DROP fresh_until');
    }
}
