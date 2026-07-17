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

/**
 * Kernel request listener enforcing mandatory 2FA enrolment for elevated
 * roles. LoginSuccessHandler redirects to /2fa/setup only at login time, so
 * this listener closes the remaining gap: an already-authenticated elevated
 * user (e.g. via remember-me) who navigates straight to a protected URL
 * without a TOTP secret is redirected here too, on every main request. See
 * BYPASS_PREFIXES below for the paths this listener never touches.
 *
 * @see docs/specs/account-and-auth.md §4
 *
 * @api Registered as a kernel.request listener via #[AsEventListener]; autoconfigured.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final class TwoFactorSetupEnforcer
{
    /** Path prefixes that must always pass through (no redirect, no token read). */
    private const array BYPASS_PREFIXES = [
        '/2fa',      // scheb interstitial (/2fa, /2fa_check); covered by the TwoFactorToken guard
        '/_wdt',
        '/_profiler',
        '/assets',
        // Public, explicitly-cacheable data endpoints (security.yaml PUBLIC_ACCESS,
        // docs/specs/account-and-auth.md §5). Listed here so we never call
        // getToken() for them: an eager token read boots the lazy firewall and
        // a session, which would defeat their cacheability.
        '/map',      // /map page + /map/catalog.json, /map/best-of, /map/item/*/history
        '/routes/',  // /routes/{id}.gpx
        '/items/',   // /items/{id}/confirmations (public tallies), /items/{id}/confirm
    ];

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly TwoFactorPolicy $twoFactorPolicy,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // Path-based bypass FIRST, before touching the token: public/cacheable
        // endpoints (and the bypass prefixes) must never trigger a token read,
        // which would boot the lazy firewall and a session on their behalf.
        $path = $event->getRequest()->getPathInfo();
        foreach (self::BYPASS_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        // Allow the setup route itself and logout (exact match to generated URLs),
        // also before the token read, for the same reason.
        $setupPath = $this->urlGenerator->generate('2fa_setup');
        if ($path === $setupPath) {
            return;
        }
        try {
            if ($path === $this->urlGenerator->generate('logout')) {
                return;
            }
        } catch (\Exception) {
            // Route may not exist in all environments, safe to ignore.
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

        // One shared, role-hierarchy-aware policy decides who must enrol and
        // whether 2FA is actually active (twoFaEnabled && secret); the same
        // check the login handler uses.
        if (!$this->twoFactorPolicy->requiresSetup($user)) {
            return;
        }

        $event->setResponse(new RedirectResponse($setupPath));
    }
}
