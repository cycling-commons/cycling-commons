<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Service;

use App\Entity\User;
use App\Repository\ResetPasswordRequestRepository;

/**
 * Purges a user's pending password-reset requests before account removal.
 * reset_password_request.user_id is a restrictive FK (no ON DELETE action),
 * so without this hook BOTH deletion paths (self-service confirm and admin
 * removal) throw a foreign-key violation whenever a live reset request
 * exists.
 *
 * @see docs/specs/account-and-auth.md §6.3
 *
 * @api Discovered via the app.user_deletion_hook tag; run by UserDeletionService::purge().
 */
final class ResetPasswordCleanupHook implements UserDeletionHookInterface
{
    public function __construct(private readonly ResetPasswordRequestRepository $resetRequests)
    {
    }

    #[\Override]
    public function preDelete(User $user): void
    {
        // Bundle-provided bulk delete of every request row for this user
        // (ResetPasswordRequestRepositoryTrait::removeRequests).
        $this->resetRequests->removeRequests($user);
    }
}
