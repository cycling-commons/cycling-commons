<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Roadmap;

use App\Entity\User;
use App\Service\UserDeletionHookInterface;
use Doctrine\DBAL\Connection;

/**
 * Account deletion: a rider's votes, follows and proposals go with the
 * account, except a proposal that is open for votes or was promoted, which
 * stays without its author. It never showed a name, so nothing on the page
 * changes; the row just stops pointing at anybody.
 *
 * The picture goes with a deleted proposal (ON DELETE CASCADE) and stays
 * with a kept one, where only the desk can see it.
 *
 * @see docs/specs/roadmap-and-changelog.md §8
 * @see docs/specs/account-and-auth.md §6.3
 *
 * @api
 */
final class RoadmapDeletionHook implements UserDeletionHookInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly RoadmapMarks $marks,
    ) {
    }

    #[\Override]
    public function preDelete(User $user): void
    {
        $id = (int) $user->getId();
        $this->marks->forgetRider($id);
        $this->db->executeStatement(
            "DELETE FROM roadmap_proposal WHERE user_id = :u AND status IN ('pending', 'declined')",
            ['u' => $id],
        );
        $this->db->executeStatement('UPDATE roadmap_proposal SET user_id = NULL WHERE user_id = :u', ['u' => $id]);
        // The desk member who decided: the decision stays, the name does not.
        $this->db->executeStatement('UPDATE roadmap_proposal SET decided_by = NULL WHERE decided_by = :u', ['u' => $id]);
    }
}
