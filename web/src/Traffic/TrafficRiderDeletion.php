<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Traffic;

use App\Entity\User;
use App\Service\UserDeletionHookInterface;

/**
 * Deletes a leaving account's rider rows (TrafficStore::deleteRidersOf). The
 * totals stay: they hold no rider.
 *
 * @see docs/specs/traffic-measurements.md §4.7
 *
 * @api
 */
final class TrafficRiderDeletion implements UserDeletionHookInterface
{
    public function __construct(private readonly TrafficStore $store)
    {
    }

    #[\Override]
    public function preDelete(User $user): void
    {
        $this->store->deleteRidersOf((int) $user->getId());
    }
}
