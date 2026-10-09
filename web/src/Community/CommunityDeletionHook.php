<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Community;

use App\Entity\User;
use App\Service\UserDeletionHookInterface;
use Doctrine\DBAL\Connection;

/**
 * Account deletion: curator applications go; a country request stays as a
 * count of one rider, without the rider.
 *
 * - The rider's curator applications are deleted, whatever their status: the
 *   about text, the OSM username and the social link describe the person and
 *   are worth nothing without them.
 * - Their country and area requests stay, because the admin demand page counts
 *   how many riders asked for an area. The row loses the account, the note
 *   and the offer to curate: nobody is left to make the offer good.
 * - Applications they decided as an administrator stay and lose the decider.
 *
 * @see docs/specs/account-and-auth.md §6.3
 * @see docs/specs/moderation-and-contribution.md §11
 *
 * @api
 */
final class CommunityDeletionHook implements UserDeletionHookInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    #[\Override]
    public function preDelete(User $user): void
    {
        $id = (int) $user->getId();

        $this->db->executeStatement('DELETE FROM curator_application WHERE user_id = :u', ['u' => $id]);
        $this->db->executeStatement('UPDATE curator_application SET decided_by = NULL WHERE decided_by = :u', ['u' => $id]);
        $this->db->executeStatement(
            'UPDATE country_interest SET user_id = NULL, note = NULL, willing_to_curate = FALSE WHERE user_id = :u',
            ['u' => $id],
        );
    }
}
