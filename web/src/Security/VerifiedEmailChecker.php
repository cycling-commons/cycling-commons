<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * No sign-in before the address is confirmed.
 *
 * Post-auth, after the password: refusing earlier would tell anyone who types
 * an address, with any password, that it is waiting for confirmation.
 *
 * @see docs/specs/account-and-auth.md §2
 *
 * @api
 */
final class VerifiedEmailChecker implements UserCheckerInterface
{
    public const string UNVERIFIED = 'security.login.error_unverified';

    #[\Override]
    public function checkPreAuth(UserInterface $user): void
    {
    }

    #[\Override]
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($user instanceof User && !$user->isEmailVerified()) {
            throw new CustomUserMessageAccountStatusException(self::UNVERIFIED);
        }
    }
}
