<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Service;

use App\Entity\User;
use App\Repository\ResetPasswordRequestRepository;

/**
 * Drop pending reset-password rows before account removal (restrictive FK).
 *
 * @see docs/specs/account-and-auth.md §6.3
 *
 * @api
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
