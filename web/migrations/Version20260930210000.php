<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * moderation_seen: which desk items each curator has opened, one row per
 * curator and item. Open work with no row for its reader carries the unseen
 * bar; settled work never does, whoever opened it (DeskSeen::unseenAmong()).
 *
 * The table starts empty: every open item arrives unopened for every curator,
 * which is what the bar is for (owner 2026-09-30).
 *
 * @see docs/specs/moderation-and-contribution.md §5.2f
 */
final class Version20260930210000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'moderation_seen: which desk items each curator has opened, starting empty';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE moderation_seen (
                user_id BIGINT NOT NULL,
                subject_type VARCHAR(32) NOT NULL,
                subject_id VARCHAR(64) NOT NULL,
                seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (user_id, subject_type, subject_id),
                CONSTRAINT fk_moderation_seen_user
                    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql("COMMENT ON TABLE moderation_seen IS 'Desk items each curator has opened; a list row with no row for its reader carries the unseen bar while it is open work. subject_id holds the id as text (a UUID for reports and photos).'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE moderation_seen');
    }
}
