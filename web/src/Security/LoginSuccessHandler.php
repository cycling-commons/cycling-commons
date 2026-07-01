<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\HttpUtils;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Firewall success handler invoked once a user is *fully* authenticated.
 *
 * Integration with scheb/2fa (v8): for users who have 2FA enabled, scheb's own
 * {@see \Scheb\TwoFactorBundle\Security\Http\Authenticator\TwoFactorAuthenticator}
 * intercepts the primary login, shows the interstitial, and only calls THIS handler
 * after the second factor is verified. So when we run we are always fully authenticated —
 * we never double-handle the 2FA challenge.
 *
 * Enforcement policy (spec §7.3): 2FA is optional for ROLE_USER but mandatory for
 * ROLE_CURATOR / ROLE_ADMIN. An elevated-role user who has not yet provisioned a TOTP
 * secret is redirected to /2fa/setup; everyone else lands on the default target.
 *
 * @api Registered as the `main` firewall `success_handler`; instantiated by the container,
 *      never referenced from application code.
 */
final class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    use TargetPathTrait;

    private const string FIREWALL_NAME = 'main';
    private const string DEFAULT_TARGET_ROUTE = 'profile';
    private const string TWO_FACTOR_LOGIN_ROUTE = '2fa_login';

    public function __construct(
        private readonly HttpUtils $httpUtils,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[\Override]
    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        // For users with 2FA enabled, scheb has already swapped the token for a TwoFactorToken
        // during AuthenticationTokenCreatedEvent. Send them straight to the interstitial instead
        // of bouncing through the default target while still only half-authenticated. scheb's
        // TwoFactorAccessListener would enforce this on the next protected request anyway; this
        // just makes the redirect immediate. We DO NOT verify the code here — that is scheb's job
        // at /2fa_check, which calls this handler again once the user is fully authenticated.
        if ($token instanceof TwoFactorTokenInterface) {
            return new RedirectResponse($this->urlGenerator->generate(self::TWO_FACTOR_LOGIN_ROUTE));
        }

        $user = $token->getUser();

        // Apply the user's saved language preference for the rest of the session.
        if ($user instanceof User && null !== $user->getLocale()) {
            $request->getSession()->set('_locale', $user->getLocale());
        }

        if ($user instanceof User && $this->requiresTwoFactorSetup($user)) {
            return new RedirectResponse($this->urlGenerator->generate('2fa_setup'));
        }

        $targetPath = $this->getTargetPath($request->getSession(), self::FIREWALL_NAME);
        if (null !== $targetPath) {
            $this->removeTargetPath($request->getSession(), self::FIREWALL_NAME);

            return $this->httpUtils->createRedirectResponse($request, $targetPath);
        }

        return new RedirectResponse($this->urlGenerator->generate(self::DEFAULT_TARGET_ROUTE));
    }

    /**
     * An elevated-role user (curator/admin) who has no TOTP secret yet must provision 2FA.
     * Users who already have a secret have, by this point, already passed scheb's interstitial.
     */
    private function requiresTwoFactorSetup(User $user): bool
    {
        if (null !== $user->getTotpSecret()) {
            return false;
        }

        $roles = $user->getRoles();

        return \in_array('ROLE_CURATOR', $roles, true) || \in_array('ROLE_ADMIN', $roles, true);
    }
}
