<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * No remember-me cookie for a curator or admin who still has to set up two-factor.
 *
 * scheb withholds the cookie only while its own 2FA token is in play, and an
 * account with no secret never gets one, so without this "Stay signed in"
 * mints a seven-day REMEMBERME on the very login that LoginSuccessHandler
 * sends to /2fa/setup. TwoFactorSetupEnforcer redirects a request carrying
 * such a cookie anyway; this keeps the cookie from existing before there is a
 * second factor behind it. The rider ticks the box again at the first login
 * after enrolling.
 *
 * Priority -48: after Symfony's CheckRememberMeConditionsListener (-32) has
 * enabled the badge, before its RememberMeListener (-64) writes the cookie.
 *
 * @see docs/specs/account-and-auth.md §4
 *
 * @api
 */
final readonly class NoRememberMeBeforeTwoFactor
{
    public function __construct(private TwoFactorPolicy $twoFactorPolicy)
    {
    }

    #[AsEventListener(event: LoginSuccessEvent::class, priority: -48)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $passport = $event->getPassport();
        if (!$passport->hasBadge(RememberMeBadge::class)) {
            return;
        }
        $user = $event->getUser();
        if (!$user instanceof User || !$this->twoFactorPolicy->requiresSetup($user)) {
            return;
        }

        $badge = $passport->getBadge(RememberMeBadge::class);
        if ($badge instanceof RememberMeBadge) {
            $badge->disable();
        }
    }
}
