<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Per-account brute-force lockout: 5 failures, then 15 minutes. Complements
 * per-IP throttling.
 *
 * This also guards the 2FA interstitial, and that is not a side effect to
 * refactor away. Symfony's authenticator manager dispatches CheckPassportEvent
 * and LoginFailureEvent for EVERY authenticator on the firewall, so scheb's
 * TwoFactorAuthenticator runs through both listeners below: a wrong TOTP code
 * counts against the same budget, and the fifth one locks the account so that
 * even a correct code is refused. /2fa_login_check has no limiter of its own
 * and needs none.
 *
 * A 2026-08-25 security scan read the missing limiter as a missing gate and
 * filed it as unlimited code guessing on elevated accounts. It was wrong, but
 * only because of the wiring above, which nothing stated and nothing tested.
 * Narrowing this listener to the password step would make the report true.
 * TwoFactorBruteForceTest pins the behaviour; keep it passing.
 *
 * @see docs/specs/account-and-auth.md §3
 * @see \App\Tests\Auth\TwoFactorBruteForceTest
 *
 * @api
 */
final class LoginThrottleListener
{
    private const int LOCKOUT_THRESHOLD = 5;
    private const int LOCKOUT_MINUTES = 15;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * Reject locked accounts before credentials are verified.
     */
    #[AsEventListener(event: CheckPassportEvent::class, priority: 0)]
    public function onCheckPassport(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();

        if (!$passport->hasBadge(UserBadge::class)) {
            return;
        }

        /** @var UserBadge $badge */
        $badge = $passport->getBadge(UserBadge::class);

        try {
            $user = $badge->getUser();
        } catch (\Throwable) {
            return;
        }

        if (!$user instanceof User) {
            return;
        }

        if ($user->isLocked()) {
            throw new CustomUserMessageAuthenticationException('Account temporarily locked. Try again later.');
        }
    }

    /**
     * Increment failed-attempt counter; hard-lock at threshold. Does not leak user existence.
     */
    #[AsEventListener(event: LoginFailureEvent::class)]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $user = $this->resolveUserFromFailureEvent($event);

        if (null === $user) {
            return;
        }

        if ($user->isLocked()) {
            return; // already locked: do not re-arm the 15-minute window
        }

        if (null !== $user->getLockedUntil()) {
            $user->setLockedUntil(null);
            $user->setFailedLoginAttempts(0);
        }

        $attempts = $user->getFailedLoginAttempts() + 1;
        $user->setFailedLoginAttempts($attempts);

        if ($attempts >= self::LOCKOUT_THRESHOLD) {
            $user->setLockedUntil(new \DateTimeImmutable(sprintf('+%d minutes', self::LOCKOUT_MINUTES)));
        }

        $this->em->flush();
    }

    /**
     * Reset counters on a successful login.
     */
    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        if (0 === $user->getFailedLoginAttempts() && null === $user->getLockedUntil()) {
            return;
        }

        $user->setFailedLoginAttempts(0);
        $user->setLockedUntil(null);

        $this->em->flush();
    }

    private function resolveUserFromFailureEvent(LoginFailureEvent $event): ?User
    {
        $passport = $event->getPassport();

        if (null !== $passport && $passport->hasBadge(UserBadge::class)) {
            /** @var UserBadge $badge */
            $badge = $passport->getBadge(UserBadge::class);

            try {
                $resolved = $badge->getUser();
                if ($resolved instanceof User) {
                    return $resolved;
                }
            } catch (\Throwable) {
            }

            $identifier = $badge->getUserIdentifier();

            return $this->userRepository->findByEmail($identifier);
        }

        $identifier = $event->getRequest()->request->getString('_username');
        if ('' === $identifier) {
            return null;
        }

        return $this->userRepository->findByEmail($identifier);
    }
}
