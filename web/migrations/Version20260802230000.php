<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Escalation and legal hold for suspected illegal content
 * (docs/specs/photo-uploads.md §6d).
 *
 * Before this, a curator meeting illegal material had two verbs and both were
 * wrong for it: Reject leaves it reachable in the queue, and Trash destroys it
 * at once — including the evidence that it ever existed, which is precisely
 * what must survive until it has been reported. Escalation is the third path:
 * out of everyone's reach, destroyable by nothing, visible only to an admin.
 */
final class Version20260802230000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'media_upload: escalation columns (legal hold for suspected illegal content)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_upload ADD escalated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE media_upload ADD escalated_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE media_upload ADD escalated_reason TEXT DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN media_upload.escalated_at IS '(DC2Type:datetime_immutable) non-null = legal hold; see photo-uploads.md 6d'");
        // The admin worklist is "everything currently held", which is a tiny
        // slice of a large table.
        $this->addSql('CREATE INDEX idx_media_escalated ON media_upload (escalated_at) WHERE escalated_at IS NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_media_escalated');
        $this->addSql('ALTER TABLE media_upload DROP escalated_at');
        $this->addSql('ALTER TABLE media_upload DROP escalated_by_id');
        $this->addSql('ALTER TABLE media_upload DROP escalated_reason');
    }
}
