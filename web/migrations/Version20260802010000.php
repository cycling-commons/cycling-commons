<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Takedown requests from people who are IN a photo
 * (docs/specs/photo-uploads.md §6c).
 *
 * The uploader route (§6b) shipped with two columns; the third-party route
 * reuses them and adds who asked (source), what they say is wrong (category),
 * an optional reply address (contact, swept 90 days after resolution), a
 * salted reporter-IP hash for pattern detection, the decision timestamp the
 * retention measures from, and the per-category finality ledger.
 */
final class Version20260802010000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'media_upload third-party takedown columns: source, category, contact, reporter hash, resolved_at, decided categories';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_upload ADD takedown_source VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE media_upload ADD takedown_category VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE media_upload ADD takedown_contact VARCHAR(320) DEFAULT NULL');
        $this->addSql('ALTER TABLE media_upload ADD takedown_reporter_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE media_upload ADD takedown_resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE media_upload ADD takedown_decided_categories JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql("COMMENT ON COLUMN media_upload.takedown_source IS 'uploader|third_party — App\\Media\\MediaTakedownSource'");
        $this->addSql("COMMENT ON COLUMN media_upload.takedown_category IS 'App\\Media\\MediaTakedownCategory; third-party requests only'");
        $this->addSql("COMMENT ON COLUMN media_upload.takedown_contact IS 'reporter reply address; swept 90 days after takedown_resolved_at'");
        $this->addSql("COMMENT ON COLUMN media_upload.takedown_resolved_at IS '(DC2Type:datetime_immutable)'");
        // Any takedown recorded before this migration came through the only
        // route that existed: the uploader's own.
        $this->addSql("UPDATE media_upload SET takedown_source = 'uploader' WHERE takedown_requested_at IS NOT NULL");
        // The pattern-detection query is "how many photos has this hash
        // reported" — an index the size of the takedown tail, not the table.
        $this->addSql('CREATE INDEX idx_media_takedown_reporter ON media_upload (takedown_reporter_hash) WHERE takedown_reporter_hash IS NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_media_takedown_reporter');
        $this->addSql('ALTER TABLE media_upload DROP takedown_source');
        $this->addSql('ALTER TABLE media_upload DROP takedown_category');
        $this->addSql('ALTER TABLE media_upload DROP takedown_contact');
        $this->addSql('ALTER TABLE media_upload DROP takedown_reporter_hash');
        $this->addSql('ALTER TABLE media_upload DROP takedown_resolved_at');
        $this->addSql('ALTER TABLE media_upload DROP takedown_decided_categories');
    }
}
