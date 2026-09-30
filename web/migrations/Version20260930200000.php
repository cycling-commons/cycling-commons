<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * curator_post_read: which room posts each curator has opened, one row per
 * curator and post. It replaces curator_room_visit, the single "last had the
 * room open" stamp that counted every post as seen once the board loaded.
 *
 * The backfill keeps every count where it was: a post older than a curator's
 * last visit arrives read, and a curator who never opened the room (who
 * counted nothing) has every existing post read. Posts newer than the visit
 * stay unread, as they were.
 *
 * @see docs/specs/moderation-and-contribution.md §13.7
 */
final class Version20260930200000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'curator_post_read: per-post read state for the curator room, replacing curator_room_visit';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE curator_post_read (
                user_id BIGINT NOT NULL,
                post_id BIGINT NOT NULL,
                read_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (user_id, post_id),
                CONSTRAINT fk_curator_post_read_user
                    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_curator_post_read_post
                    FOREIGN KEY (post_id) REFERENCES curator_post (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_curator_post_read_post ON curator_post_read (post_id)');
        $this->addSql("COMMENT ON TABLE curator_post_read IS 'Room posts each curator has opened; feeds the Room tab count. A post with no row for a curator is unread to them.'");

        $this->addSql(<<<'SQL'
            INSERT INTO curator_post_read (user_id, post_id, read_at)
            SELECT u.id, p.id, COALESCE(v.last_seen_at, now())
              FROM users u
              LEFT JOIN curator_room_visit v ON v.user_id = u.id
              JOIN curator_post p
                ON (p.author_id IS NULL OR p.author_id <> u.id)
               AND (p.recipient_id IS NULL OR p.recipient_id = u.id)
             WHERE (v.user_id IS NOT NULL
                    OR u.roles::jsonb @> '["ROLE_CURATOR"]'::jsonb
                    OR u.roles::jsonb @> '["ROLE_ADMIN"]'::jsonb)
               AND (v.user_id IS NULL OR p.created_at <= v.last_seen_at)
            SQL);

        $this->addSql('DROP TABLE curator_room_visit');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE curator_room_visit (
                user_id BIGINT PRIMARY KEY,
                last_seen_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                CONSTRAINT fk_curator_room_visit_user
                    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql("COMMENT ON TABLE curator_room_visit IS 'When each curator last had the room open; feeds the Room tab count. No row means nothing is counted.'");
        $this->addSql('INSERT INTO curator_room_visit (user_id, last_seen_at) SELECT user_id, MAX(read_at) FROM curator_post_read GROUP BY user_id');
        $this->addSql('DROP TABLE curator_post_read');
    }
}
