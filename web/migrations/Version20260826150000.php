<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The curator room: an in-desk board, and each curator's last visit to it.
 *
 * A board row per post, not an inbox row per reader: `user_message` would need
 * one copy per curator for a post addressed to everybody, and an edit per copy
 * for a pinned one.
 *
 * @see docs/specs/moderation-and-contribution.md §13.3
 */
final class Version20260826150000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'curator_post / curator_room_visit: the curator room board and its per-curator visit stamp';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE curator_post (
                id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                author_id BIGINT DEFAULT NULL,
                category VARCHAR(32) DEFAULT NULL,
                recipient_id BIGINT DEFAULT NULL,
                pin VARCHAR(8) NOT NULL DEFAULT 'none',
                body TEXT NOT NULL,
                about_submission_id BIGINT DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                edited_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                CONSTRAINT fk_curator_post_author
                    FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_curator_post_recipient
                    FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_curator_post_submission
                    FOREIGN KEY (about_submission_id) REFERENCES submission (id) ON DELETE SET NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_curator_post_pin ON curator_post (pin, created_at DESC)');
        $this->addSql('CREATE INDEX idx_curator_post_recipient ON curator_post (recipient_id, created_at DESC)');
        $this->addSql("COMMENT ON TABLE curator_post IS 'Curator room board. recipient_id NULL means addressed to every curator. No region column: the room is deliberately unscoped.'");
        $this->addSql("COMMENT ON COLUMN curator_post.author_id IS 'SET NULL on account deletion: the room keeps the note, loses the name.'");

        $this->addSql(<<<'SQL'
            CREATE TABLE curator_room_visit (
                user_id BIGINT PRIMARY KEY,
                last_seen_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                CONSTRAINT fk_curator_room_visit_user
                    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql("COMMENT ON TABLE curator_room_visit IS 'When each curator last had the room open; feeds the Room tab count. No row means nothing is counted.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE curator_room_visit');
        $this->addSql('DROP TABLE curator_post');
    }
}
