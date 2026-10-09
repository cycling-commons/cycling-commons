<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Moderation\StatementOfReasons;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * No sign-in while an administrator's suspension runs, by password or by the
 * remember-me cookie (docs/specs/account-and-auth.md §6.8).
 *
 * Post-auth, after the password, as {@see VerifiedEmailChecker} is: refusing
 * earlier would tell anyone who types an address that the account is
 * suspended. Whoever knows the password is told until when, in their own
 * language and time zone.
 *
 * @api
 */
#[AutoconfigureTag('security.user_checker.main', ['priority' => -10])]
final readonly class SuspensionChecker implements UserCheckerInterface
{
    public const string SUSPENDED = 'account_suspension.login_refused';

    public function __construct(
        private ClockInterface $clock,
        private RequestStack $requests,
    ) {
    }

    #[\Override]
    public function checkPreAuth(UserInterface $user): void
    {
    }

    #[\Override]
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User || !$user->isSuspendedAt($this->clock->now())) {
            return;
        }
        $until = $user->getSuspendedUntil();
        \assert(null !== $until);
        $locale = $this->requests->getCurrentRequest()?->getLocale() ?? $user->getLocale() ?? 'en';

        throw new CustomUserMessageAccountStatusException(self::SUSPENDED, ['%until%' => StatementOfReasons::moment($until, $locale, $user->getTimeZone() ?? $user->getDetectedTimeZone() ?? 'Europe/Amsterdam')]);
    }
}
