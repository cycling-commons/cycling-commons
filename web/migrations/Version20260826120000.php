<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Curator-published wording on a translation proposal, distinct from the rider's original.
 *
 * @see docs/specs/translations.md §3.1, §5
 */
final class Version20260826120000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'translation_proposal.published_value: wording that went live (may be a curator typo fix)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE translation_proposal ADD published_value TEXT DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN translation_proposal.published_value IS 'Value written to the overlay on approve. NULL until approved; may differ from proposed_value when a curator copy-edited. translations.md §5'");
        $this->addSql("UPDATE translation_proposal SET published_value = proposed_value WHERE status = 'approved' AND published_value IS NULL");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE translation_proposal DROP published_value');
    }
}
