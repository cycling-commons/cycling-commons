<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
 * Redirect elevated users who still need 2FA setup. LoginSuccessHandler only covers login.
 *
 * @see docs/specs/account-and-auth.md §4
 *
 * @api
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final class TwoFactorSetupEnforcer
{
    /** Path prefixes that skip redirect and token read. */
    private const array BYPASS_PREFIXES = [
        '/2fa',
        '/_wdt',
        '/_profiler',
        '/assets',
        // Public cacheable endpoints: never call getToken() (docs/specs/account-and-auth.md §5).
        '/map',
        '/routes/',
        '/items/',
    ];

    /**
     * The remember-me cookie's name: security.yaml sets none, so Symfony's
     * default. TwoFactorTest replays the cookie the login really set, so a
     * rename there fails that test rather than reopening the gap.
     */
    private const string REMEMBER_ME_COOKIE = 'REMEMBERME';

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

        // No session cookie and no remember-me cookie, nobody signed in,
        // nothing to enforce: do not ask for a token. Since the Symfony 7.4
        // patch releases of September 2026 the first token read on the lazy
        // firewall counts as session use, so that one call turned every
        // anonymous page private and uncacheable (RoadmapChangelogTest,
        // 2026-09-21). A signed-in user carries one of the two: the session,
        // or on the first request of a new browser session only the
        // remember-me cookie, which the token read below turns into a login.
        // That second case is the one a stolen cookie arrives as, so it is
        // checked like the first.
        $request = $event->getRequest();
        if (!$request->hasPreviousSession() && !$request->cookies->has(self::REMEMBER_ME_COOKIE)) {
            return;
        }

        $path = $request->getPathInfo();
        foreach (self::BYPASS_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        $setupPath = $this->urlGenerator->generate('2fa_setup');
        if ($path === $setupPath) {
            return;
        }
        try {
            if ($path === $this->urlGenerator->generate('logout')) {
                return;
            }
        } catch (\Exception) {
            // Logout route may be absent in some environments.
        }

        $token = $this->tokenStorage->getToken();
        if (null === $token) {
            return;
        }

        if ($token instanceof TwoFactorTokenInterface) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return;
        }

        if (!$this->twoFactorPolicy->requiresSetup($user)) {
            return;
        }

        $event->setResponse(new RedirectResponse($setupPath));
    }
}
