<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

use App\Entity\User;
use App\Service\UserDeletionHookInterface;
use Doctrine\DBAL\Connection;

/**
 * Account deletion: a rider's correspondence and turned-down contributions go
 * with the account.
 *
 * Messages about contributions are kept while the account exists and no
 * longer (owner 2026-10-01). On deletion, by the rider, by an administrator or
 * by the dormancy sweep, all through UserDeletionService::purge():
 *
 * - their rejected and withdrawn submissions and their dismissed route
 *   corrections are deleted with their threads, also while one of them sits
 *   in Trash;
 * - every message addressed to them, and every reply they wrote to a curator,
 *   is deleted, whatever it is about;
 * - approved contributions stay, credited to nobody, as before; so do
 *   pending ones, which a curator may still decide.
 *
 * Legal hold overrides all of it (docs/specs/photo-uploads.md §6d): a held
 * submission and its whole thread stay. The thread's messages to the rider
 * keep a NULL recipient (user_message.user_id is ON DELETE SET NULL), and the
 * rider's replies on it keep a NULL sender.
 *
 * A message the account wrote as a curator to another rider stays in that
 * rider's inbox, from nobody (`sender_id` NULL).
 * Photos are MediaDeletionHook's: it drops what was never approved.
 *
 * @see docs/specs/account-and-auth.md §6.3
 * @see docs/specs/moderation-and-contribution.md §8
 *
 * @api
 */
final class ContributionDeletionHook implements UserDeletionHookInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    #[\Override]
    public function preDelete(User $user): void
    {
        $id = (int) $user->getId();

        $this->db->executeStatement(
            "WITH gone AS (
                 DELETE FROM submission
                  WHERE user_id = :u AND escalated_at IS NULL
                    AND (status IN ('rejected', 'withdrawn')
                         OR (status = 'trashed' AND trashed_from IN ('rejected', 'withdrawn')))
                 RETURNING id
             )
             DELETE FROM user_message um USING gone WHERE um.channel = 'submission' AND um.ref_id = gone.id",
            ['u' => $id],
        );
        $this->db->executeStatement(
            "WITH gone AS (
                 DELETE FROM route_suggestion
                  WHERE user_id = :u
                    AND (status = 'dismissed' OR (status = 'trashed' AND trashed_from = 'dismissed'))
                 RETURNING id
             )
             DELETE FROM user_message um USING gone WHERE um.channel = 'correction' AND um.ref_id = gone.id",
            ['u' => $id],
        );
        $this->db->executeStatement(
            "DELETE FROM user_message
              WHERE (user_id = :u OR (sender = 'rider' AND sender_id = :u))
                AND NOT (channel = 'submission'
                         AND ref_id IN (SELECT id FROM submission WHERE escalated_at IS NOT NULL))",
            ['u' => $id],
        );
        $this->db->executeStatement('UPDATE user_message SET sender_id = NULL WHERE sender_id = :u', ['u' => $id]);
    }
}
