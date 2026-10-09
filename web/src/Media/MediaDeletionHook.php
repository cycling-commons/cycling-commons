<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

use App\Entity\User;
use App\Service\UserDeletionHookInterface;
use Doctrine\DBAL\Connection;

/**
 * Account deletion: drop unmoderated work; anonymize approved credit.
 *
 * What the account did as a curator on other riders' photos (escalating one,
 * confirming where it was taken, recording as an administrator that an
 * authority was informed about it, every logged moderation step) stays and
 * names nobody. The rider's licence grants in `consent_record` stay as the record
 * that the licence was given, and lose the account.
 *
 * @see docs/specs/photo-uploads.md §6
 * @see docs/specs/account-and-auth.md §6.3
 *
 * @api
 */
final class MediaDeletionHook implements UserDeletionHookInterface
{
    public function __construct(
        private readonly MediaDisposalService $disposal,
        private readonly Connection $db,
    ) {
    }

    #[\Override]
    public function preDelete(User $user): void
    {
        $this->disposal->anonymizeFor($user);

        $id = (int) $user->getId();
        foreach ([
            ['media_upload', 'escalated_by_id'],
            ['media_upload', 'authority_notified_by_id'],
            ['media_upload', 'location_confirmed_by'],
            ['media_moderation_event', 'actor_id'],
            ['consent_record', 'user_id'],
        ] as [$table, $column]) {
            $this->db->executeStatement(\sprintf('UPDATE %1$s SET %2$s = NULL WHERE %2$s = :u', $table, $column), ['u' => $id]);
        }
    }
}
