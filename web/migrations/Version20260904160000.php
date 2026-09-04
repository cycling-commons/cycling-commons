<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The history behind every provider edit.
 *
 * A provider's rank decides which of two records of one place a rider sees,
 * and its licence decides what the site claims it may republish. Both are
 * moderation acts, so both leave a trail, the same way `change_history`
 * records an item edit and `media_moderation_event` records a photo decision.
 *
 * Append-only: nothing here is ever updated or deleted. The provider row
 * answers "what is it now"; this answers "how did it get there".
 *
 * @see docs/specs/data-provider-hierarchy.md §8
 */
final class Version20260904160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'data_provider_change: append-only history for the curator provider desk';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE data_provider_change (
                id BIGSERIAL PRIMARY KEY,
                provider_id BIGINT NOT NULL,
                field VARCHAR(40) NOT NULL,
                old_value TEXT DEFAULT NULL,
                new_value TEXT DEFAULT NULL,
                changed_by BIGINT DEFAULT NULL,
                changed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
            )
            SQL);
        // The desk reads one provider's trail newest first, which is the only
        // way this table is ever read.
        $this->addSql('CREATE INDEX idx_provider_change_time ON data_provider_change (provider_id, changed_at DESC)');
        $this->addSql('ALTER TABLE data_provider_change ADD CONSTRAINT fk_provider_change_provider FOREIGN KEY (provider_id) REFERENCES data_provider (id) ON DELETE CASCADE');
        // NULL actor = the system did it (a seed, a migration, a scheduled
        // harvest), which is a real answer and not a missing one. ON DELETE
        // SET NULL so closing an account never erases what was decided.
        $this->addSql('ALTER TABLE data_provider_change ADD CONSTRAINT fk_provider_change_actor FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE data_provider_change');
    }
}
