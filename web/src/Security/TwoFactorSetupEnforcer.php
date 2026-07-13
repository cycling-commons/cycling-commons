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
    /** Path prefixes that must always pass through (no redirect, no token read). */
    private const array BYPASS_PREFIXES = [
        '/2fa',      // scheb interstitial (/2fa, /2fa_check) — covered by TwoFactorToken guard
        '/_wdt',
        '/_profiler',
        '/assets',
        // Public, explicitly-cacheable data endpoints (security.yaml PUBLIC_ACCESS).
        // Listed here so we never call getToken() for them — an eager token read
        // boots the lazy firewall + a session and defeats their cacheability (#16).
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
        // which would boot the lazy firewall + a session on their behalf (#16).
        $path = $event->getRequest()->getPathInfo();
        foreach (self::BYPASS_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        // Allow the setup route itself and logout (exact match to generated URLs)
        // — also before the token read, for the same reason.
        $setupPath = $this->urlGenerator->generate('2fa_setup');
        if ($path === $setupPath) {
            return;
        }
        try {
            if ($path === $this->urlGenerator->generate('logout')) {
                return;
            }
        } catch (\Exception) {
            // Route may not exist in all environments — safe to ignore.
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
        // whether 2FA is actually active (twoFaEnabled && secret) — the same
        // check the login handler uses (reviews #31 + #39).
        if (!$this->twoFactorPolicy->requiresSetup($user)) {
            return;
        }

        $event->setResponse(new RedirectResponse($setupPath));
    }
}
