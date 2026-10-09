<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Blog;

use App\Entity\User;
use App\Service\UserDeletionHookInterface;
use Doctrine\DBAL\Connection;

/**
 * Account deletion: a post outlives the account that wrote it and names
 * nobody.
 *
 * @see docs/specs/account-and-auth.md §6.3
 *
 * @api
 */
final class BlogDeletionHook implements UserDeletionHookInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    #[\Override]
    public function preDelete(User $user): void
    {
        $this->db->executeStatement('UPDATE blog_post SET author_id = NULL WHERE author_id = :u', ['u' => (int) $user->getId()]);
    }
}
