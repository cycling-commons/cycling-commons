<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DSA Art. 18: the record that the competent authority was informed about a
 * held photo or submission (docs/specs/operations.md §7).
 *
 * Four nullable columns on each held-row table: when the authority was
 * informed, which administrator recorded it (a users.id, cleared on account
 * deletion), which authority, and its reference if it gave one. A held row
 * without the record is overdue after AuthorityNotifications::OVERDUE_HOURS.
 */
final class Version20261009060000 extends AbstractMigration
{
    /** Table => the type its existing user columns use. */
    private const array TABLES = ['media_upload' => 'INT', 'submission' => 'BIGINT'];

    #[\Override]
    public function getDescription(): string
    {
        return 'DSA Art. 18: authority notification record on media_upload and submission';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table => $idType) {
            $this->addSql(\sprintf('ALTER TABLE %s ADD authority_notified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL', $table));
            $this->addSql(\sprintf('ALTER TABLE %s ADD authority_notified_by_id %s DEFAULT NULL', $table, $idType));
            $this->addSql(\sprintf('ALTER TABLE %s ADD authority_name VARCHAR(200) DEFAULT NULL', $table));
            $this->addSql(\sprintf('ALTER TABLE %s ADD authority_reference VARCHAR(200) DEFAULT NULL', $table));
            $this->addSql(\sprintf("COMMENT ON COLUMN %s.authority_notified_at IS 'DSA Art. 18: when an administrator informed the competent authority; NULL on a held row past 24 hours = overdue.'", $table));
            $this->addSql(\sprintf("COMMENT ON COLUMN %s.authority_notified_by_id IS 'users.id of the administrator who recorded the notification; NULL once their account is deleted, the record stays.'", $table));
            $this->addSql(\sprintf("COMMENT ON COLUMN %s.authority_name IS 'DSA Art. 18: the authority informed, as the administrator named it.'", $table));
            $this->addSql(\sprintf("COMMENT ON COLUMN %s.authority_reference IS 'DSA Art. 18: the authority''s case or report reference, if it gave one.'", $table));
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            foreach (['authority_notified_at', 'authority_notified_by_id', 'authority_name', 'authority_reference'] as $column) {
                $this->addSql(\sprintf('ALTER TABLE %s DROP %s', $table, $column));
            }
        }
    }
}
