<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Trash is a bin, and a thread lives as long as the rider's account (owner
 * 2026-10-01).
 *
 * Trash keeps a trashed submission, route correction or route proposal for 30
 * days (App\Moderation\TrashBin::TRASH_DAYS) with its message thread, hidden
 * everywhere but the curators' Trash list, and restorable to the state it had.
 * The row records when, by whom, and the status (or, for a route, the state)
 * it had: `submission.status` and `route_suggestion.status` become 'trashed',
 * `recommended_route.state` becomes 'trashed', and `trashed_from` holds what a
 * restore puts back. `user_message.trashed_at` hides the thread's messages from
 * every inbox while the row is in the bin.
 *
 * `user_message.user_id` changes from ON DELETE CASCADE to ON DELETE SET NULL.
 * Account deletion now deletes a rider's messages itself
 * (App\Moderation\ContributionDeletionHook), except the thread of a submission
 * under legal hold, which outlives the account (docs/specs/photo-uploads.md
 * §6d). A held thread's messages to the rider keep their place with a NULL
 * recipient: in nobody's inbox, still on the hold's record.
 *
 * @see docs/specs/moderation-and-contribution.md §6, §8
 */
final class Version20261001210000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Trash bin: trashed_at/trashed_by/trashed_from on submission, route_suggestion, recommended_route; user_message.trashed_at; user_message.user_id ON DELETE SET NULL';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        foreach (['submission', 'route_suggestion', 'recommended_route'] as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s ADD trashed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL', $table));
            $this->addSql(\sprintf('ALTER TABLE %s ADD trashed_by BIGINT DEFAULT NULL', $table));
            $this->addSql(\sprintf('ALTER TABLE %s ADD trashed_from VARCHAR(12) DEFAULT NULL', $table));
            $this->addSql(\sprintf('CREATE INDEX idx_%1$s_trashed ON %1$s (trashed_at) WHERE trashed_at IS NOT NULL', $table));
            $this->addSql(\sprintf("COMMENT ON COLUMN %s.trashed_at IS 'When a curator moved the row to Trash; NULL when it is not in the bin. Deleted for good TrashBin::TRASH_DAYS (30) days later.'", $table));
            $this->addSql(\sprintf("COMMENT ON COLUMN %s.trashed_by IS 'users.id of the curator who moved it to Trash. No FK, like decided_by.'", $table));
        }
        $this->addSql("COMMENT ON COLUMN submission.trashed_from IS 'The status the submission had before Trash; a restore puts it back.'");
        $this->addSql("COMMENT ON COLUMN route_suggestion.trashed_from IS 'The status the correction had before Trash; a restore puts it back.'");
        $this->addSql("COMMENT ON COLUMN recommended_route.trashed_from IS 'The state the proposal had before Trash (submitted or rejected); a restore puts it back.'");

        $this->addSql('ALTER TABLE user_message ADD trashed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN user_message.trashed_at IS 'Set while the row this thread is about is in Trash: the message is in no inbox until a restore clears it.'");

        $this->addSql('ALTER TABLE user_message DROP CONSTRAINT fk_user_message_user');
        $this->addSql('ALTER TABLE user_message ALTER user_id DROP NOT NULL');
        $this->addSql('ALTER TABLE user_message ADD CONSTRAINT fk_user_message_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("COMMENT ON COLUMN user_message.user_id IS 'Recipient. NULL only for a message on the thread of a held submission whose rider deleted their account.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_message DROP CONSTRAINT fk_user_message_user');
        $this->addSql('DELETE FROM user_message WHERE user_id IS NULL');
        $this->addSql('ALTER TABLE user_message ALTER user_id SET NOT NULL');
        $this->addSql('ALTER TABLE user_message ADD CONSTRAINT fk_user_message_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE user_message DROP trashed_at');

        // Whatever is in the bin goes back to where it was: the old schema has no bin.
        $this->addSql("UPDATE submission SET status = trashed_from WHERE status = 'trashed' AND trashed_from IS NOT NULL");
        $this->addSql("UPDATE route_suggestion SET status = trashed_from WHERE status = 'trashed' AND trashed_from IS NOT NULL");
        $this->addSql("UPDATE recommended_route SET state = trashed_from WHERE state = 'trashed' AND trashed_from IS NOT NULL");
        $this->addSql("UPDATE item SET state = 'submitted' WHERE state = 'trashed'");
        foreach (['submission', 'route_suggestion', 'recommended_route'] as $table) {
            $this->addSql(\sprintf('DROP INDEX idx_%s_trashed', $table));
            $this->addSql(\sprintf('ALTER TABLE %s DROP trashed_at', $table));
            $this->addSql(\sprintf('ALTER TABLE %s DROP trashed_by', $table));
            $this->addSql(\sprintf('ALTER TABLE %s DROP trashed_from', $table));
        }
    }
}
