<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A town card's local text records whether it is based on the Wikipedia
 * article (owner 2026-10-01: the curator decides, when approving, whether the
 * Wikipedia credit is still needed; docs/specs/moderation-and-contribution.md
 * §3.1b). A region's lead already keeps the same flag as `derived` in its
 * `context_curated` entry.
 *
 * `town_summary.derived` is read only on a local row (`edited_at` set): true
 * keeps the Wikipedia link and "after Wikipedia" in the card's credit, false
 * credits the writer alone. Local rows that exist today keep the credit they
 * show now: true where the row has a Wikipedia article to credit, the
 * licence-safe reading.
 */
final class Version20261001120000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'town_summary.derived: whether a local town text is based on the Wikipedia article (keeps the Wikipedia credit)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE town_summary ADD derived BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('UPDATE town_summary SET derived = TRUE WHERE edited_at IS NOT NULL AND page_url IS NOT NULL');
        $this->addSql("COMMENT ON COLUMN town_summary.derived IS 'On a local text (edited_at set): true when it is based on the Wikipedia article, so the card keeps the Wikipedia link and credit; false when it was written fresh. The approving curator decides it.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE town_summary DROP derived');
    }
}
