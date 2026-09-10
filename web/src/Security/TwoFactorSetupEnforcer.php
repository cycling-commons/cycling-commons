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

        $path = $event->getRequest()->getPathInfo();
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
