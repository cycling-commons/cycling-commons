<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notice and action for everything that is not a photo.
 *
 * DSA Article 16 asks for a mechanism any person can use, with no account, for
 * any content they believe is illegal. Photos had one; a route description, a
 * display name, a region text and a message had nothing.
 *
 * **No foreign keys, and that is deliberate.** The reporter may have no account
 * at all, so there is nothing to point at; and the target is one of five
 * different tables, so a key to each would mean five nullable columns and five
 * joins on a desk that only ever shows a label. What survives instead is a
 * type, an id, and a snapshot of what the thing said, so a report stays
 * readable after the content it names has been edited or deleted.
 *
 * @see docs/specs/content-reports.md
 */
final class Version20260828140000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'content_report: notice-and-action for routes, items, region texts, display names and messages.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE content_report (
                id UUID NOT NULL,
                target_type VARCHAR(16) NOT NULL,
                target_id VARCHAR(64) NOT NULL,
                target_label TEXT DEFAULT NULL,
                ground VARCHAR(32) NOT NULL,
                reason TEXT NOT NULL,
                reporter_contact VARCHAR(180) DEFAULT NULL,
                reporter_key VARCHAR(64) NOT NULL,
                status VARCHAR(16) NOT NULL,
                decision_note TEXT DEFAULT NULL,
                decided_by_id INT DEFAULT NULL,
                decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                author_told_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        // The desk reads open reports oldest first; the second index answers
        // "has this thing been reported before", which a curator always asks.
        $this->addSql('CREATE INDEX idx_content_report_status ON content_report (status, created_at)');
        $this->addSql('CREATE INDEX idx_content_report_target ON content_report (target_type, target_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE content_report');
    }
}
