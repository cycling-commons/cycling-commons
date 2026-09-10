<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Index the message dashboard's own predicate.
 *
 * `MessageService::listFor()` and `countFor()` both read
 * `WHERE user_id = :u OR sender_id = :u` — the thread carries what was sent TO
 * the reader and what they sent themselves. Only `user_id` was indexed (via
 * `idx_user_message_unread`), so the `sender_id` half of every OR forced a
 * sequential scan, and since the dashboard began paging (2026-08-08) that scan
 * runs twice per page load: once to count, once to fetch.
 *
 * Two indexes rather than one composite, because the planner wants to satisfy
 * each arm of the OR separately (BitmapOr) and then order:
 *   - `sender_id` covers the arm that had nothing.
 *   - `(user_id, created_at DESC, id DESC)` matches the list's ORDER BY, so the
 *     common case (messages addressed to you) pages without a sort.
 */
final class Version20260808090000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Index user_message.sender_id and (user_id, created_at DESC, id DESC) for the paged messages dashboard';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_user_message_sender ON user_message (sender_id)');
        $this->addSql('CREATE INDEX idx_user_message_user_recent ON user_message (user_id, created_at DESC, id DESC)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_user_message_user_recent');
        $this->addSql('DROP INDEX idx_user_message_sender');
    }
}
