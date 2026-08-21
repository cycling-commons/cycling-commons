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
 * After full authentication: seed locale, then 2FA setup if still required.
 *
 * @see docs/specs/account-and-auth.md §4
 *
 * @api
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
        private readonly TwoFactorPolicy $twoFactorPolicy,
    ) {
    }

    #[\Override]
    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        if ($token instanceof TwoFactorTokenInterface) {
            return new RedirectResponse($this->urlGenerator->generate(self::TWO_FACTOR_LOGIN_ROUTE));
        }

        $user = $token->getUser();

        $locale = $user instanceof User ? $user->getLocale() : null;
        if (null !== $locale) {
            $request->getSession()->set('_locale', $locale);
        }

        if ($user instanceof User && $this->twoFactorPolicy->requiresSetup($user)) {
            return new RedirectResponse($this->localizedUrl('2fa_setup', $locale));
        }

        $targetPath = $this->getTargetPath($request->getSession(), self::FIREWALL_NAME);
        if (null !== $targetPath) {
            $this->removeTargetPath($request->getSession(), self::FIREWALL_NAME);

            return $this->httpUtils->createRedirectResponse($request, $targetPath);
        }

        // Default target in the user's locale so LocaleListener does not clobber session locale.
        return new RedirectResponse($this->localizedUrl(self::DEFAULT_TARGET_ROUTE, $locale));
    }

    private function localizedUrl(string $route, ?string $locale): string
    {
        $params = null !== $locale ? ['_locale' => $locale] : [];

        return $this->urlGenerator->generate($route, $params);
    }
}
