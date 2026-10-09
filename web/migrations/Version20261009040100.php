<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Deleted personal data does not come back (owner 2026-10-09): the rule
 * Version20261009040000 made room for, applied to accounts deleted before it.
 *
 * Every id with no `users` row is treated as a deleted account. Their curator
 * applications are deleted, their rides on routes they proposed are deleted,
 * their country requests lose the note and the offer to curate, their bug
 * reports lose the address and the address hash, and every other reference
 * becomes NULL. `changed_by = 0` and `submission.user_id = 0` are the system
 * actor and stay. The audit trail kept the address of every account an
 * administrator removed ("Removed account: <email>"); those notes lose the
 * address. Then the index that lets a deletion find an editor's history
 * rows.
 *
 * Not one transaction: each statement commits on its own and holds its
 * table's row locks only while it runs, and the index is built CONCURRENTLY,
 * which Postgres refuses inside a transaction. Every statement only touches
 * rows still naming a missing account, so a run that stopped halfway, or a
 * database where the earlier combined migration already did all of it, is
 * finished by running this again.
 *
 * @see docs/specs/account-and-auth.md §6.3
 */
final class Version20261009040100 extends AbstractMigration
{
    /** References to a deleted account that become NULL, as table => columns. */
    private const array UNLINK = [
        'item_confirmation' => ['user_id'],
        'route_ride' => ['user_id'],
        'change_history' => ['changed_by'],
        'route_change_history' => ['changed_by'],
        'submission' => ['user_id', 'decided_by', 'escalated_by_id', 'trashed_by'],
        'route_suggestion' => ['user_id', 'resolved_by', 'trashed_by'],
        'recommended_route' => ['proposed_by', 'trashed_by'],
        'catalog_finding' => ['decided_by'],
        'town_summary' => ['edited_by', 'approved_by'],
        'curator_application' => ['decided_by'],
        'consent_record' => ['user_id'],
        'bug_report' => ['handled_by_user_id'],
        'contact_message' => ['user_id', 'handled_by_user_id'],
        'content_report' => ['decided_by_id'],
        'blog_post' => ['author_id'],
        'media_upload' => ['user_id', 'escalated_by_id', 'location_confirmed_by'],
        'media_moderation_event' => ['actor_id'],
        'user_message' => ['sender_id'],
    ];

    /** Columns where 0 is the system actor, not an account. */
    private const array SYSTEM_ZERO = ['change_history.changed_by', 'submission.user_id'];

    private const string INDEX = 'idx_change_history_changed_by';

    #[\Override]
    public function getDescription(): string
    {
        return 'Account erasure: references left by earlier deletions are deleted or unlinked; removal notes lose the address; index on change_history.changed_by';
    }

    #[\Override]
    public function isTransactional(): bool
    {
        return false;
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        // Earlier deletions: what goes with the person.
        $this->addSql('DELETE FROM curator_application t WHERE '.$this->orphan('curator_application', 'user_id'));
        $this->addSql('DELETE FROM route_ride t USING recommended_route r
                        WHERE r.id = t.route_id AND r.proposed_by = t.user_id AND '.$this->orphan('route_ride', 'user_id'));
        $this->addSql('UPDATE country_interest t SET user_id = NULL, note = NULL, willing_to_curate = FALSE WHERE '.$this->orphan('country_interest', 'user_id'));
        $this->addSql('UPDATE bug_report t SET user_id = NULL, reporter_email = NULL, ip_hash = NULL WHERE '.$this->orphan('bug_report', 'user_id'));

        // Earlier deletions: what stays, unlinked.
        foreach (self::UNLINK as $table => $columns) {
            foreach ($columns as $column) {
                $this->addSql(\sprintf('UPDATE %1$s t SET %2$s = NULL WHERE %3$s', $table, $column, $this->orphan($table, $column)));
            }
        }
        foreach (['_credit', '_corrected'] as $field) {
            $this->addSql(\sprintf(
                "UPDATE submission t SET payload = jsonb_set(t.payload, '{%1\$s,by}', 'null'::jsonb)
                  WHERE jsonb_typeof(t.payload->'%1\$s'->'by') = 'number'
                    AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id = (t.payload->'%1\$s'->>'by')::bigint)",
                $field,
            ));
        }
        foreach (['userId', 'approvedBy'] as $field) {
            $this->addSql(\sprintf(
                "UPDATE region r SET context_curated = (
                        SELECT jsonb_object_agg(e.key,
                                   CASE WHEN jsonb_typeof(e.value->'%1\$s') = 'number'
                                         AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id = (e.value->>'%1\$s')::bigint)
                                        THEN jsonb_set(e.value, '{%1\$s}', 'null'::jsonb)
                                        ELSE e.value END)
                          FROM jsonb_each(r.context_curated) e)
                  WHERE jsonb_typeof(r.context_curated) = 'object'
                    AND EXISTS (SELECT 1 FROM jsonb_each(r.context_curated) e
                                 WHERE jsonb_typeof(e.value->'%1\$s') = 'number'
                                   AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id = (e.value->>'%1\$s')::bigint))",
                $field,
            ));
        }

        $this->addSql("UPDATE admin_action_log SET note = 'Removed account' WHERE action = 'remove_account' AND note LIKE 'Removed account: %'");

        // A CONCURRENTLY build that failed leaves an invalid index behind,
        // which IF NOT EXISTS would keep: drop it so the build runs again.
        $invalid = $this->connection->fetchOne(
            'SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(:name) AND NOT indisvalid',
            ['name' => self::INDEX],
        );
        if (false !== $invalid) {
            $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
        }
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX.' ON change_history (changed_by)');
    }

    /** The unlinked and deleted rows cannot be given back: only the index goes. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
    }

    /** The column holds an id that is not the system actor and has no `users` row. */
    private function orphan(string $table, string $column): string
    {
        $system = \in_array($table.'.'.$column, self::SYSTEM_ZERO, true) ? \sprintf(' AND t.%s <> 0', $column) : '';

        return \sprintf('t.%1$s IS NOT NULL%2$s AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id = t.%1$s)', $column, $system);
    }
}
