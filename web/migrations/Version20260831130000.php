<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * English versions, stale flags, and English as an overlay locale.
 *
 * docs/specs/translations.md §3.3 and §4.2 (2026-08-31). Every entry starts at
 * version 1 with the git wording as its live wording; every existing overlay
 * and proposal is recorded as made against version 1, which is true: nothing
 * could have moved English before this migration ran.
 *
 * The two locale CHECKs learn `en`. `consent_record_id` becomes nullable
 * because an English edit is product copy, not a CC BY-SA grant (translations.md §6).
 */
final class Version20260831130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Translation entry versions; en as an overlay locale; consent nullable on proposals';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE translation_entry ADD english_yaml TEXT NOT NULL DEFAULT ''");
        $this->addSql('UPDATE translation_entry SET english_yaml = english');
        $this->addSql('ALTER TABLE translation_entry ALTER COLUMN english_yaml DROP DEFAULT');
        $this->addSql('ALTER TABLE translation_entry ADD english_version INT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE translation_entry ADD yaml_english_version INT NOT NULL DEFAULT 1');

        $this->addSql('ALTER TABLE translation_overlay ADD english_version INT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE translation_overlay DROP CONSTRAINT chk_translation_overlay_locale');
        $this->addSql("ALTER TABLE translation_overlay ADD CONSTRAINT chk_translation_overlay_locale CHECK (locale IN ('en', 'fr', 'nl', 'de', 'es'))");

        $this->addSql('ALTER TABLE translation_proposal ADD english_version_at_submit INT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE translation_proposal ALTER COLUMN consent_record_id DROP NOT NULL');
        $this->addSql('ALTER TABLE translation_proposal DROP CONSTRAINT chk_translation_proposal_locale');
        $this->addSql("ALTER TABLE translation_proposal ADD CONSTRAINT chk_translation_proposal_locale CHECK (locale IN ('en', 'fr', 'nl', 'de', 'es'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM translation_overlay WHERE locale = 'en'");
        $this->addSql("DELETE FROM translation_proposal WHERE locale = 'en'");
        $this->addSql('ALTER TABLE translation_proposal DROP CONSTRAINT chk_translation_proposal_locale');
        $this->addSql("ALTER TABLE translation_proposal ADD CONSTRAINT chk_translation_proposal_locale CHECK (locale IN ('fr', 'nl', 'de', 'es'))");
        $this->addSql('ALTER TABLE translation_proposal ALTER COLUMN consent_record_id SET NOT NULL');
        $this->addSql('ALTER TABLE translation_proposal DROP COLUMN english_version_at_submit');
        $this->addSql('ALTER TABLE translation_overlay DROP CONSTRAINT chk_translation_overlay_locale');
        $this->addSql("ALTER TABLE translation_overlay ADD CONSTRAINT chk_translation_overlay_locale CHECK (locale IN ('fr', 'nl', 'de', 'es'))");
        $this->addSql('ALTER TABLE translation_overlay DROP COLUMN english_version');
        $this->addSql('ALTER TABLE translation_entry DROP COLUMN yaml_english_version');
        $this->addSql('ALTER TABLE translation_entry DROP COLUMN english_version');
        $this->addSql('ALTER TABLE translation_entry DROP COLUMN english_yaml');
    }
}
