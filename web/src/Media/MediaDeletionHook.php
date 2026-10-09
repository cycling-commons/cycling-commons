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
    }
}
