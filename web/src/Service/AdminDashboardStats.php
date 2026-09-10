<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;

/**
 * Assembles the admin dashboard snapshot from live User-table counts.
 * All numbers from `users` (no cache).
 *
 * @api
 */
final class AdminDashboardStats
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * @return array{members:int,curators:int,admins:int,unverified:int,locked:int,pendingRemoval:int,recent:list<User>}
     */
    public function collect(): array
    {
        return [
            'members' => $this->users->countAll(),
            'curators' => $this->users->countWithRole('ROLE_CURATOR'),
            'admins' => $this->users->countWithRole('ROLE_ADMIN'),
            'unverified' => $this->users->countUnverified(),
            'locked' => $this->users->countLocked(),
            'pendingRemoval' => $this->users->countPendingRemoval(),
            'recent' => $this->users->recentSignups(10),
        ];
    }
}
