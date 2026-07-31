<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

use App\Entity\User;
use App\Service\UserDeletionHookInterface;

/**
 * Account deletion, media side (docs/specs/photo-uploads.md §6). Pending and
 * rejected uploads are deleted outright — unmoderated work leaves with the
 * account. Approved photos stay: they are CC BY-SA-licensed contributions to
 * the commons, and pulling them would punish everyone who relies on the map for
 * one person's departure. What does go is the credit, on the row and on the
 * item, which falls back to anonymous.
 *
 * Discovered through the app.user_deletion_hook tag (the _instanceof rule in
 * services.yaml tags it automatically); run by UserDeletionService::purge().
 *
 * @api Deletion hook.
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
