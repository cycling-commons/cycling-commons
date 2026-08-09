<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Pagination;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Turns a list's own page size into the reader's, when the reader has asked
 * for one.
 *
 * Every paged surface passes the size it was designed around and gets back
 * either that number (the `auto` default, and the only answer available to a
 * signed-out reader) or the rider's explicit choice. Keeping the surface
 * default in the CALL rather than in here is the point: the resolver has no
 * opinion about how long a message list should be, and adding a paged list
 * never means editing this class.
 *
 * @see docs/specs/account-and-auth.md §9
 *
 * @api Injected by every controller that renders a paged list.
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
