<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Deleted personal data does not come back; contributions stay without naming
 * the person (owner 2026-10-09).
 *
 * The contribution tables whose user column was NOT NULL accept NULL, so
 * account deletion can unlink a row instead of leaving the deleted id in it:
 * item_confirmation, route_ride, change_history, route_change_history,
 * submission, route_suggestion, consent_record and country_interest. The
 * deletion hooks do the unlinking from now on (docs/specs/account-and-auth.md
 * §6.3).
 *
 * Schema only: dropping NOT NULL and commenting change the catalogue, not the
 * rows, so the exclusive locks on the eight tables are brief.
 * Version20261009040100 applies the same rule to the ids earlier deletions
 * left behind, one statement at a time.
 */
final class Version20261009040000 extends AbstractMigration
{
    /** Columns that become nullable. */
    private const array NULLABLE = [
        'item_confirmation' => 'user_id',
        'route_ride' => 'user_id',
        'change_history' => 'changed_by',
        'route_change_history' => 'changed_by',
        'submission' => 'user_id',
        'route_suggestion' => 'user_id',
        'consent_record' => 'user_id',
        'country_interest' => 'user_id',
    ];

    #[\Override]
    public function getDescription(): string
    {
        return 'Account erasure: user columns of contribution rows accept NULL';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        foreach (self::NULLABLE as $table => $column) {
            $this->addSql(\sprintf('ALTER TABLE %s ALTER %s DROP NOT NULL', $table, $column));
        }
        $this->addSql("COMMENT ON COLUMN item_confirmation.user_id IS 'users.id of the rider; NULL once their account is deleted, the answer still counts.'");
        $this->addSql("COMMENT ON COLUMN route_ride.user_id IS 'users.id of the rider; NULL once their account is deleted, the ride still counts.'");
        $this->addSql("COMMENT ON COLUMN change_history.changed_by IS 'users.id of the editor, 0 for the system, NULL once the editor''s account is deleted.'");
        $this->addSql("COMMENT ON COLUMN route_change_history.changed_by IS 'users.id of the editor, NULL once the editor''s account is deleted.'");
        $this->addSql("COMMENT ON COLUMN submission.user_id IS 'users.id of the rider, 0 for the system, NULL once the rider''s account is deleted.'");
        $this->addSql("COMMENT ON COLUMN route_suggestion.user_id IS 'users.id of the rider, NULL once the rider''s account is deleted.'");
        $this->addSql("COMMENT ON COLUMN consent_record.user_id IS 'users.id of the rider who granted it, NULL once their account is deleted; the grant stays.'");
        $this->addSql("COMMENT ON COLUMN country_interest.user_id IS 'users.id of the rider asking, NULL once their account is deleted; the request still counts, without note or offer.'");
        $this->addSql("COMMENT ON TABLE consent_record IS 'Append-only licence grants. The grant outlives the account: account deletion clears user_id and nothing else.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("COMMENT ON TABLE consent_record IS 'Append-only licence grants. Never updated, never deleted: the grant outlives the account.'");
        // Unlinked rows have no owner to give back; the old schema cannot hold them.
        $this->addSql('DELETE FROM country_interest WHERE user_id IS NULL');
        $this->addSql('DELETE FROM route_ride WHERE user_id IS NULL');
        $this->addSql('DELETE FROM item_confirmation WHERE user_id IS NULL');
        $this->addSql('DELETE FROM route_suggestion WHERE user_id IS NULL');
        $this->addSql('UPDATE change_history SET changed_by = 0 WHERE changed_by IS NULL');
        $this->addSql('UPDATE submission SET user_id = 0 WHERE user_id IS NULL');
        $this->addSql('UPDATE route_change_history SET changed_by = 0 WHERE changed_by IS NULL');
        $this->addSql('UPDATE consent_record SET user_id = 0 WHERE user_id IS NULL');
        foreach (self::NULLABLE as $table => $column) {
            $this->addSql(\sprintf('ALTER TABLE %s ALTER %s SET NOT NULL', $table, $column));
        }
    }
}
