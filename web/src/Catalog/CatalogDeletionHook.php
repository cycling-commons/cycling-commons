<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use App\Entity\User;
use App\Service\UserDeletionHookInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Account deletion: the rider's map contributions stay and name nobody.
 *
 * Owner 2026-10-09: deleted personal data does not come back; contributions
 * stay without naming the person. Every catalog row that holds the account's
 * id keeps its content and loses the id (NULL):
 *
 * - their item confirmations and route rides, which still count as evidence
 *   and as ride counts;
 * - the item and route change history they made, which still shows the edit
 *   and names nobody;
 * - their submissions and route corrections that stay (approved, pending,
 *   applied), and the routes they proposed;
 * - what they did as a curator: decided, escalated, trashed or resolved
 *   submissions (and, as an administrator, the authority notifications they
 *   recorded on held ones), corrections, routes and catalog findings, the town texts and
 *   region leads they wrote or approved, and the credit and correction marks
 *   inside a text proposal's payload.
 *
 * A ride on a route they proposed themselves never counted toward that route
 * and is deleted. `changed_by = 0` is the system actor and is never an account.
 *
 * Runs after every other hook (priority -100): ContributionDeletionHook finds
 * the rider's turned-down rows, and SeasonVoteDeletionHook stores closed
 * results, by the ids this hook clears.
 *
 * @see docs/specs/account-and-auth.md §6.3
 *
 * @api
 */
#[AsTaggedItem(priority: -100)]
final class CatalogDeletionHook implements UserDeletionHookInterface
{
    /** Columns that keep the row and lose the account, as table => columns. */
    public const array UNLINK = [
        'item_confirmation' => ['user_id'],
        'route_ride' => ['user_id'],
        'change_history' => ['changed_by'],
        'route_change_history' => ['changed_by'],
        'submission' => ['user_id', 'decided_by', 'escalated_by_id', 'authority_notified_by_id', 'trashed_by'],
        'route_suggestion' => ['user_id', 'resolved_by', 'trashed_by'],
        'recommended_route' => ['proposed_by', 'trashed_by'],
        'catalog_finding' => ['decided_by'],
        'town_summary' => ['edited_by', 'approved_by'],
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    #[\Override]
    public function preDelete(User $user): void
    {
        $id = (int) $user->getId();
        $text = (string) $id;

        $this->db->executeStatement(
            'DELETE FROM route_ride rr USING recommended_route r
              WHERE r.id = rr.route_id AND rr.user_id = :u AND r.proposed_by = :u',
            ['u' => $id],
        );

        foreach (self::UNLINK as $table => $columns) {
            foreach ($columns as $column) {
                $this->db->executeStatement(\sprintf('UPDATE %1$s SET %2$s = NULL WHERE %2$s = :u', $table, $column), ['u' => $id]);
            }
        }

        foreach (['_credit', '_corrected'] as $key) {
            $this->db->executeStatement(
                \sprintf("UPDATE submission SET payload = jsonb_set(payload, '{%1\$s,by}', 'null'::jsonb) WHERE payload->'%1\$s'->>'by' = :u", $key),
                ['u' => $text],
            );
        }

        // A revoked approval's record names who revoked and who had approved
        // (moderation-and-contribution.md §5.6): those names go, the record stays.
        foreach (['by', 'approvedBy'] as $key) {
            $this->db->executeStatement(
                \sprintf(
                    "UPDATE submission SET payload = jsonb_set(payload, '{_revoked}', (
                            SELECT jsonb_agg(CASE WHEN t.e->>'%1\$s' = :u THEN jsonb_set(t.e, '{%1\$s}', 'null'::jsonb) ELSE t.e END ORDER BY t.o)
                              FROM jsonb_array_elements(payload->'_revoked') WITH ORDINALITY AS t(e, o)))
                      WHERE jsonb_typeof(payload->'_revoked') = 'array'
                        AND EXISTS (SELECT 1 FROM jsonb_array_elements(payload->'_revoked') r WHERE r->>'%1\$s' = :u)",
                    $key,
                ),
                ['u' => $text],
            );
        }
        // The text an approved proposal replaced, kept for a revoke, names its writer and approver.
        foreach (['userId', 'approvedBy', 'edited_by', 'approved_by'] as $key) {
            $this->db->executeStatement(
                \sprintf("UPDATE submission SET payload = jsonb_set(payload, '{_before,entry,%1\$s}', 'null'::jsonb) WHERE payload->'_before'->'entry'->>'%1\$s' = :u", $key),
                ['u' => $text],
            );
        }

        // A region's lead and its history text keep their words; the writer and approver go.
        foreach (['context_curated', 'story'] as $column) {
            foreach (['userId', 'approvedBy'] as $key) {
                $this->db->executeStatement(
                    \sprintf(
                        "UPDATE region r SET %2\$s = (
                                SELECT jsonb_object_agg(e.key, CASE WHEN e.value->>'%1\$s' = :u THEN jsonb_set(e.value, '{%1\$s}', 'null'::jsonb) ELSE e.value END)
                                  FROM jsonb_each(r.%2\$s) e)
                          WHERE jsonb_typeof(r.%2\$s) = 'object'
                            AND EXISTS (SELECT 1 FROM jsonb_each(r.%2\$s) e WHERE e.value->>'%1\$s' = :u)",
                        $key,
                        $column,
                    ),
                    ['u' => $text],
                );
            }
        }
    }
}
