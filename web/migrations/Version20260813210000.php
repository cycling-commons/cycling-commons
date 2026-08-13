<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A confirmation can now say "no, and here is why".
 *
 * The surface segment's negative stance (`not_as_described`, owner
 * 2026-08-13) is 16 characters against the column's 12, and it may carry a
 * note telling the curators what differs — free text for the desk, never
 * rendered publicly (one-way-to-moderate: public free text without moderation
 * would be a second publishing channel).
 */
final class Version20260813210000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'item_confirmation: stance widens for not_as_described; nullable note for the curators — edit-items/A-road-surface.md';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item_confirmation ALTER stance TYPE VARCHAR(20)');
        $this->addSql('ALTER TABLE item_confirmation ADD note VARCHAR(500) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item_confirmation DROP note');
        $this->addSql('ALTER TABLE item_confirmation ALTER stance TYPE VARCHAR(12)');
    }
}
