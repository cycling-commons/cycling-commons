<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A rider asking for their own photo to be taken down
 * (docs/specs/photo-uploads.md §6b).
 *
 * Two columns and no third. Whether the request was granted or declined is not
 * a column: granting deletes the objects and sets objects_deleted_at, declining
 * clears takedown_requested_at, and both append a row to
 * media_moderation_event, which is where this domain already keeps its history.
 * A pending request is therefore "requested and not yet tombstoned", which is
 * one index away rather than one more piece of state to keep consistent.
 */
final class Version20260801120000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'media_upload: a rider can ask for their own photo to be taken down';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_upload ADD takedown_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE media_upload ADD takedown_reason TEXT DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN media_upload.takedown_requested_at IS 'When the uploader asked for this photo to be removed. Non-null withholds it from publication immediately (GDPR Art. 18), pending a curator decision.'");
        $this->addSql("COMMENT ON COLUMN media_upload.takedown_reason IS 'The rider''s own words. A curator needs them to tell a data-protection claim from a change of mind.'");
        // Pending requests only: the partial index stays the size of the queue,
        // not the size of the table.
        $this->addSql('CREATE INDEX idx_media_takedown ON media_upload (takedown_requested_at) WHERE takedown_requested_at IS NOT NULL AND objects_deleted_at IS NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_media_takedown');
        $this->addSql('ALTER TABLE media_upload DROP takedown_reason');
        $this->addSql('ALTER TABLE media_upload DROP takedown_requested_at');
    }
}
