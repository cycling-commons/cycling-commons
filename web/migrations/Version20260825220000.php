<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * In-site translation catalogue projection, rider proposals, and live overlays.
 *
 * @see docs/specs/translations.md §3.1
 */
final class Version20260825220000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'translation_entry / translation_proposal / translation_overlay: in-site translation persistence';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE translation_entry (
                id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                message_key VARCHAR(255) NOT NULL,
                english TEXT NOT NULL,
                synced_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                absent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_translation_entry_message_key ON translation_entry (message_key)');
        $this->addSql('CREATE INDEX idx_translation_entry_message_key_trgm ON translation_entry USING gin (message_key gin_trgm_ops)');
        $this->addSql('CREATE INDEX idx_translation_entry_english_trgm ON translation_entry USING gin (english gin_trgm_ops)');
        $this->addSql("COMMENT ON TABLE translation_entry IS 'Projection of messages.en.yaml keys; identity is message_key, never display wording.'");

        $this->addSql(<<<'SQL'
            CREATE TABLE translation_proposal (
                id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                entry_id BIGINT NOT NULL,
                locale VARCHAR(2) NOT NULL,
                proposed_value TEXT NOT NULL,
                english_at_submit TEXT NOT NULL,
                submitter_id BIGINT DEFAULT NULL,
                consent_record_id UUID NOT NULL,
                status VARCHAR(12) NOT NULL,
                reviewer_id BIGINT DEFAULT NULL,
                reviewer_note TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                decided_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                CONSTRAINT fk_translation_proposal_entry
                    FOREIGN KEY (entry_id) REFERENCES translation_entry (id),
                CONSTRAINT fk_translation_proposal_submitter
                    FOREIGN KEY (submitter_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_translation_proposal_consent
                    FOREIGN KEY (consent_record_id) REFERENCES consent_record (id),
                CONSTRAINT fk_translation_proposal_reviewer
                    FOREIGN KEY (reviewer_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT chk_translation_proposal_locale
                    CHECK (locale IN ('fr', 'nl', 'de', 'es'))
            )
            SQL);
        $this->addSql("CREATE UNIQUE INDEX uniq_translation_proposal_open ON translation_proposal (submitter_id, locale, entry_id) WHERE status IN ('pending', 'needs_info') AND submitter_id IS NOT NULL");
        $this->addSql('CREATE INDEX idx_translation_proposal_status_locale ON translation_proposal (status, locale)');

        $this->addSql(<<<'SQL'
            CREATE TABLE translation_overlay (
                id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                entry_id BIGINT NOT NULL,
                locale VARCHAR(2) NOT NULL,
                value TEXT NOT NULL,
                source_proposal_id BIGINT DEFAULT NULL,
                approved_by_id BIGINT DEFAULT NULL,
                approved_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                CONSTRAINT fk_translation_overlay_entry
                    FOREIGN KEY (entry_id) REFERENCES translation_entry (id),
                CONSTRAINT fk_translation_overlay_source_proposal
                    FOREIGN KEY (source_proposal_id) REFERENCES translation_proposal (id) ON DELETE SET NULL,
                CONSTRAINT fk_translation_overlay_approved_by
                    FOREIGN KEY (approved_by_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT chk_translation_overlay_locale
                    CHECK (locale IN ('fr', 'nl', 'de', 'es'))
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_translation_overlay_entry_locale ON translation_overlay (entry_id, locale)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE translation_overlay');
        $this->addSql('DROP TABLE translation_proposal');
        $this->addSql('DROP TABLE translation_entry');
    }
}
