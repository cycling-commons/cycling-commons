<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Index user_message on (channel, ref_id): the thread of one submission, route
 * or correction.
 *
 * Trash (MessageService::deleteThread) and the retention sweep
 * (RetentionService::sweep) delete a whole thread by channel and ref_id, and
 * the room and the desks read threads the same way. Without this index every
 * one of those statements scans the table.
 *
 * @see docs/specs/moderation-and-contribution.md §8
 */
final class Version20260930180000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Index user_message (channel, ref_id) for thread reads and deletes';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_user_message_thread ON user_message (channel, ref_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_user_message_thread');
    }
}
