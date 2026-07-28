<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'system_setting: runtime-editable editorial thresholds, admin-owned — system-configuration.md §3';
    }

    public function up(Schema $schema): void
    {
        // Deviations only: a key with no row is at its YAML default, so a fresh
        // database behaves exactly as the pre-settings code did and this table
        // is empty on every environment until an admin changes something.
        //
        // `setting_key` / `setting_value` rather than key/value: both bare words
        // are reserved in the SQL standard, and spelling them out keeps every
        // hand-written query quote-free.
        //
        // INTEGER, not TEXT: all six settings are counts (App\Settings\SettingsRegistry
        // explains why). The first non-integer setting is meant to cost a
        // migration rather than sneak in behind a stringly-typed column.
        $this->addSql('CREATE TABLE system_setting (
            setting_key VARCHAR(64) NOT NULL,
            setting_value INT NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_by_id INT DEFAULT NULL,
            PRIMARY KEY(setting_key)
        )');
        $this->addSql('CREATE INDEX IDX_system_setting_updated_by ON system_setting (updated_by_id)');
        // SET NULL, matching admin_action_log: the audit row naming the actor
        // outlives the account, and the setting itself must survive regardless.
        $this->addSql('ALTER TABLE system_setting
            ADD CONSTRAINT FK_system_setting_updated_by
            FOREIGN KEY (updated_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE system_setting');
    }
}
