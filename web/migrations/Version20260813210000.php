<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A confirmation can now say "no".
 *
 * The surface segment's negative stance (`not_as_described`, owner
 * 2026-08-13) is 16 characters against the column's 12. Deliberately NOTHING
 * else: a "why" comment field was built the same evening and removed on
 * owner decision — what changed belongs in the edit form, the one moderation
 * pipeline, not in a second free-text entry point to the curators.
 */
final class Version20260813210000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'item_confirmation: stance widens for not_as_described — edit-items/A-road-surface.md';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item_confirmation ALTER stance TYPE VARCHAR(20)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item_confirmation ALTER stance TYPE VARCHAR(12)');
    }
}
