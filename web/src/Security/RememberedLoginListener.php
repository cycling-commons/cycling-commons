<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Authenticator\RememberMeAuthenticator;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * The dormancy clock for a rider who comes back on the remember-me cookie.
 *
 * {@see LoginSuccessHandler} stamps `lastLoginAt` after the login FORM, and
 * only there, because it runs after 2FA and so means "somebody got in". A
 * rider who ticked "remember me" gets in without the form: the cookie signs
 * them in on the first request of every new browser session, the form is
 * never shown, and the handler never runs. The cookie lives seven days and is
 * renewed on use, so a rider who visited every week for two years still had a
 * `lastLoginAt` from the last time they typed a password, and the dormancy
 * sweep ({@see \App\Account\DormancySweep}) would have warned and then closed
 * an account that was in use the whole time.
 *
 * Symfony dispatches LoginSuccessEvent for every authenticator on the
 * firewall, so this listens for the remember-me one alone. Counting it as a
 * sign-in is safe: scheb withholds the remember-me cookie until 2FA has
 * passed, so a cookie that signs somebody in was minted by a rider who
 * completed the whole login.
 *
 * Once per browser session, not per request: the remember-me authenticator
 * only runs while there is no session token yet, so this write is as rare as
 * the form login it stands in for.
 *
 * @see docs/specs/account-and-auth.md §6.5
 *
 * @api
 */
final class RememberedLoginListener
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if (!$event->getAuthenticator() instanceof RememberMeAuthenticator) {
            return;
        }

        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $user->recordLogin($this->clock->now());
        $this->em->flush();
    }
}
