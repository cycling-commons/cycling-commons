<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Pagination;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Resolve a list's page size against the reader's preference.
 *
 * @see docs/specs/account-and-auth.md §9
 *
 * @api
 */
final class PageSize
{
    public function __construct(private readonly Security $security)
    {
    }

    /**
     * @param int $surfaceDefault what this list shows when nobody has said otherwise
     */
    public function resolve(int $surfaceDefault): int
    {
        $user = $this->security->getUser();

        return ($user instanceof User ? $user->getRowsPerPage()->rows() : null) ?? $surfaceDefault;
    }
}
