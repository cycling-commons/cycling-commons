<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Escalation and legal hold for submissions
 * (docs/specs/photo-uploads.md §6d).
 *
 * The photo side got this first, but words can be illegal content just as
 * pixels can, and Trash was the only verb a curator had for either — deleting
 * the evidence along with the material. Same three columns, same hold.
 */
final class Version20260802235000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'submission: escalation columns (legal hold for suspected illegal content)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE submission ADD escalated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE submission ADD escalated_by_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE submission ADD escalated_reason TEXT DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN submission.escalated_at IS '(DC2Type:datetime_immutable) non-null = legal hold; see photo-uploads.md 6d'");
        $this->addSql('CREATE INDEX idx_submission_escalated ON submission (escalated_at) WHERE escalated_at IS NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_submission_escalated');
        $this->addSql('ALTER TABLE submission DROP escalated_at');
        $this->addSql('ALTER TABLE submission DROP escalated_by_id');
        $this->addSql('ALTER TABLE submission DROP escalated_reason');
    }
}
