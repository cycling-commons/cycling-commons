<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rider photo uploads (docs/specs/photo-uploads.md §3, §5b): the append-only
 * consent ledger, the upload lifecycle row, and the per-photo moderation event
 * log.
 */
final class Version20260731120000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Photo uploads: consent_record, media_upload, media_moderation_event';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE consent_record (
            id UUID NOT NULL,
            user_id BIGINT NOT NULL,
            kind VARCHAR(32) NOT NULL,
            version VARCHAR(16) NOT NULL,
            text_hash VARCHAR(64) NOT NULL,
            consented_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE INDEX idx_consent_user_kind ON consent_record (user_id, kind, version)');
        $this->addSql("COMMENT ON TABLE consent_record IS 'Append-only licence grants. Never updated, never deleted — the grant outlives the account.'");

        $this->addSql('CREATE TABLE media_upload (
            id UUID NOT NULL,
            user_id BIGINT DEFAULT NULL,
            consent_record_id UUID NOT NULL,
            continent CHAR(2) NOT NULL,
            status VARCHAR(10) NOT NULL,
            width INT NOT NULL,
            height INT NOT NULL,
            bytes INT NOT NULL,
            taken_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            gps_lat DOUBLE PRECISION DEFAULT NULL,
            gps_lng DOUBLE PRECISION DEFAULT NULL,
            gps_distance_m INT DEFAULT NULL,
            submission_id BIGINT DEFAULT NULL,
            item_id BIGINT DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            objects_deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            credit_frozen VARCHAR(120) DEFAULT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE INDEX idx_media_gc ON media_upload (status, created_at)');
        $this->addSql('CREATE INDEX idx_media_user ON media_upload (user_id)');
        $this->addSql('CREATE INDEX idx_media_submission ON media_upload (submission_id)');
        $this->addSql('CREATE INDEX idx_media_item ON media_upload (item_id)');
        $this->addSql('ALTER TABLE media_upload ADD CONSTRAINT fk_media_consent
            FOREIGN KEY (consent_record_id) REFERENCES consent_record (id)');
        $this->addSql("COMMENT ON COLUMN media_upload.gps_lat IS 'PRIVATE, transient: nulled at intake once the pin distance is computed.'");
        $this->addSql("COMMENT ON COLUMN media_upload.gps_lng IS 'PRIVATE, transient: nulled at intake once the pin distance is computed.'");

        $this->addSql('CREATE TABLE media_moderation_event (
            id BIGSERIAL NOT NULL,
            media_id UUID NOT NULL,
            actor_id BIGINT DEFAULT NULL,
            action VARCHAR(24) NOT NULL,
            note TEXT DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE INDEX idx_media_event_time ON media_moderation_event (media_id, created_at)');
        $this->addSql('ALTER TABLE media_moderation_event ADD CONSTRAINT fk_media_event_upload
            FOREIGN KEY (media_id) REFERENCES media_upload (id) ON DELETE CASCADE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE media_moderation_event');
        $this->addSql('DROP TABLE media_upload');
        $this->addSql('DROP TABLE consent_record');
    }
}
