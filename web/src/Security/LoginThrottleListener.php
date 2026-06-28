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
 * Per-account brute-force lockout.
 *
 * On repeated failed logins the account is hard-locked for 15 minutes, regardless
 * of whether Symfony's per-IP login_throttling has also tripped. Complementary,
 * not a replacement: IP throttle stays enabled for unenumerated-address attacks.
 *
 * Lifecycle:
 *   LoginFailureEvent  → increment failedLoginAttempts; lock at threshold.
 *   CheckPassportEvent → reject locked accounts even with the correct password.
 *   LoginSuccessEvent  → reset counters on every successful authentication.
 *
 * Threshold : 5 failed attempts (matches login_throttling default so tests can
 *             disable IP throttle and observe the account lock cleanly).
 * Cooldown  : 15 minutes from the last locking failure.
 *
 * @api Wired via #[AsEventListener] attributes; autoconfigured by the container.
 *      Never referenced directly from application code — Psalm must not flag as unused.
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
     *
     * Runs at priority 0 (default), after the UserProvider has resolved the user
     * but before the PasswordHasher listener (priority -10) verifies the password.
     * This ensures a locked account is rejected even when the supplied password is
     * correct — the password is never checked.
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

        // getUser() may throw if the identifier does not resolve; catch silently
        // so we don't leak user-existence here (failure will surface anyway).
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
     * Increment failed-attempt counter; hard-lock at threshold.
     *
     * We do NOT leak user-existence: if the passport carries no UserBadge, or
     * the identifier does not resolve to a known User, we silently return.
     */
    #[AsEventListener(event: LoginFailureEvent::class)]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $user = $this->resolveUserFromFailureEvent($event);

        if (null === $user) {
            return;
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
            // Nothing to reset — skip the flush.
            return;
        }

        $user->setFailedLoginAttempts(0);
        $user->setLockedUntil(null);

        $this->em->flush();
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function resolveUserFromFailureEvent(LoginFailureEvent $event): ?User
    {
        // Prefer the passport's UserBadge: the user may already be resolved there.
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
                // User not found via badge — fall through to identifier lookup.
            }

            // Fall back: look up by identifier from the badge (avoids getUser() exception path).
            $identifier = $badge->getUserIdentifier();

            return $this->userRepository->findByEmail($identifier);
        }

        // Last resort: read the raw login identifier from the request.
        $identifier = $event->getRequest()->request->getString('_username');
        if ('' === $identifier) {
            return null;
        }

        return $this->userRepository->findByEmail($identifier);
    }
}
