<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * A suspension ends a session that is already signed in, on its next request
 * (docs/specs/account-and-auth.md §6.8).
 *
 * The session's user is reloaded from the database on every request that
 * reads it; this answers "no such user" for a suspended one, so the firewall
 * drops the session as it would for a deleted account, and leaves the reason
 * for the sign-in page to show. Hooking the reload rather than every request
 * keeps the firewall lazy: a page that never asks who is signed in still
 * never reads the session.
 *
 * Loading by address is untouched, so a sign-in attempt still reaches the
 * password check and then {@see SuspensionChecker}, which says until when.
 *
 * @implements UserProviderInterface<User>
 *
 * @api
 */
#[AsDecorator('security.user.provider.concrete.app_users')]
final readonly class SuspendedSessionProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    /**
     * @param UserProviderInterface<User>&PasswordUpgraderInterface $inner
     */
    public function __construct(
        #[AutowireDecorated]
        private UserProviderInterface&PasswordUpgraderInterface $inner,
        private SuspensionChecker $suspension,
        private RequestStack $requests,
    ) {
    }

    #[\Override]
    public function refreshUser(UserInterface $user): UserInterface
    {
        $fresh = $this->inner->refreshUser($user);

        try {
            $this->suspension->checkPostAuth($fresh);
        } catch (CustomUserMessageAccountStatusException $suspended) {
            $session = $this->requests->getCurrentRequest()?->hasSession() ? $this->requests->getSession() : null;
            $session?->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $suspended);

            throw new UserNotFoundException('The account is suspended.', 0, $suspended);
        }

        return $fresh;
    }

    #[\Override]
    public function supportsClass(string $class): bool
    {
        return $this->inner->supportsClass($class);
    }

    #[\Override]
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return $this->inner->loadUserByIdentifier($identifier);
    }

    #[\Override]
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        $this->inner->upgradePassword($user, $newHashedPassword);
    }
}
