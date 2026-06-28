<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Kernel request listener that enforces mandatory 2FA enrolment for elevated roles.
 *
 * The LoginSuccessHandler already redirects elevated-role users without a TOTP secret to
 * /2fa/setup at login time. However that redirect is advisory — a user who is already
 * fully authenticated (e.g. via remember-me, or who simply ignores the login redirect) can
 * navigate directly to any protected URL. This subscriber closes the gap by intercepting
 * every main request and redirecting to /2fa/setup whenever:
 *
 *   - the token is a full (non-2FA-in-progress) authentication token,
 *   - the user is an App\Entity\User,
 *   - the user holds ROLE_CURATOR or higher (ROLE_ADMIN via role_hierarchy), AND
 *   - the user has not yet provisioned a TOTP secret.
 *
 * Paths exempted to avoid redirect loops or breaking the framework:
 *   - /2fa/setup (the destination itself)
 *   - /logout (must always be reachable so a locked-out user can sign out)
 *   - /2fa* (scheb's interstitial — handled by TwoFactorTokenInterface check below)
 *   - /_wdt, /_profiler (Symfony debug toolbar / profiler)
 *   - /assets (static asset serving)
 *
 * The 2FA-in-progress case (TwoFactorTokenInterface) is intentionally excluded: scheb's own
 * listeners guard the interstitial flow; we must NOT redirect those tokens to setup.
 *
 * @api Registered as a kernel.request listener via #[AsEventListener]; autoconfigured.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final class TwoFactorSetupEnforcer
{
    /** Path prefixes that must always pass through (no redirect). */
    private const array BYPASS_PREFIXES = [
        '/2fa',      // scheb interstitial (/2fa, /2fa_check) — covered by TwoFactorToken guard
        '/_wdt',
        '/_profiler',
        '/assets',
    ];

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if (null === $token) {
            return;
        }

        // Skip the scheb 2FA-in-progress token: that user is in the middle of the TOTP challenge
        // and scheb's own access listener handles them. We must not interfere.
        if ($token instanceof TwoFactorTokenInterface) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return;
        }

        // Only enforce for elevated roles (ROLE_CURATOR covers ROLE_ADMIN via role_hierarchy).
        if (!$this->authorizationChecker->isGranted('ROLE_CURATOR')) {
            return;
        }

        // User has already provisioned 2FA — nothing to enforce.
        if (null !== $user->getTotpSecret()) {
            return;
        }

        // Check the current request path against the bypass list.
        $path = $event->getRequest()->getPathInfo();
        foreach (self::BYPASS_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        // Also allow the setup route itself and logout by exact comparison to generated URLs.
        $setupPath = $this->urlGenerator->generate('2fa_setup');
        if ($path === $setupPath) {
            return;
        }

        try {
            $logoutPath = $this->urlGenerator->generate('logout');
            if ($path === $logoutPath) {
                return;
            }
        } catch (\Exception) {
            // Route may not exist in all environments — safe to ignore.
        }

        $event->setResponse(new RedirectResponse($setupPath));
    }
}
