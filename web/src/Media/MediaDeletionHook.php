<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

use App\Entity\User;
use App\Service\UserDeletionHookInterface;

/**
 * Account deletion: drop unmoderated work; anonymize approved credit.
 *
 * @see docs/specs/photo-uploads.md §6
 *
 * @api
 */
final class MediaDeletionHook implements UserDeletionHookInterface
{
    public function __construct(private readonly MediaDisposalService $disposal)
    {
    }

    #[\Override]
    public function preDelete(User $user): void
    {
        $this->disposal->anonymizeFor($user);
    }
}
