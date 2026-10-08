<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\EventSubscriber\StatelessLoginRedirectSubscriber;
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
 * After full authentication: seed locale, then 2FA setup if still required,
 * then the page the rider was sent away from, else the landing: the
 * profile page (settings) on the first sign-in since the address was confirmed,
 * the dashboard after that.
 *
 * @see docs/specs/account-and-auth.md §4
 *
 * @api
 */
final class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    use TargetPathTrait;

    private const string FIREWALL_NAME = 'main';
    private const string DEFAULT_TARGET_ROUTE = 'dashboard';
    private const string FIRST_LOGIN_ROUTE = 'settings';
    private const string TWO_FACTOR_LOGIN_ROUTE = '2fa_login';

    public function __construct(
        private readonly HttpUtils $httpUtils,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TwoFactorPolicy $twoFactorPolicy,
        private readonly \Doctrine\ORM\EntityManagerInterface $em,
        private readonly \Psr\Clock\ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        if ($token instanceof TwoFactorTokenInterface) {
            return new RedirectResponse($this->urlGenerator->generate(self::TWO_FACTOR_LOGIN_ROUTE));
        }

        $user = $token->getUser();

        // Read before the stamp below overwrites it. A new account has
        // nothing to count yet, so its first landing is its settings
        // (FIRST_LOGIN_ROUTE, account-and-auth.md §8).
        $firstLogin = $user instanceof User && self::isFirstSinceConfirmation($user);

        // The dormancy clock, and only here: this runs after 2FA, so it means
        // "somebody actually got in", not "somebody typed a password".
        // Coming back also clears any warning already sent.
        // @see \App\Account\DormancyLadder
        if ($user instanceof User) {
            $user->recordLogin($this->clock->now());
            $this->em->flush();
        }

        $locale = $user instanceof User ? $user->getLocale() : null;
        if (null !== $locale) {
            $request->getSession()->set('_locale', $locale);
        }

        if ($user instanceof User && $this->twoFactorPolicy->requiresSetup($user)) {
            return new RedirectResponse($this->localizedUrl('2fa_setup', $locale));
        }

        // The way back: the sign-in form's `_target_path`, which an anonymous
        // visitor's link carries instead of a session
        // (StatelessLoginRedirectSubscriber), and a local path only; else the
        // one a session holds, for a visitor who already had a session.
        $posted = StatelessLoginRedirectSubscriber::localPath($request->request->getString('_target_path'));
        $targetPath = $posted ?? $this->getTargetPath($request->getSession(), self::FIREWALL_NAME);
        if (null !== $targetPath) {
            $this->removeTargetPath($request->getSession(), self::FIREWALL_NAME);

            return $this->httpUtils->createRedirectResponse($request, $targetPath);
        }

        // Default target in the user's locale so LocaleListener does not clobber session locale.
        return new RedirectResponse($this->localizedUrl($firstLogin ? self::FIRST_LOGIN_ROUTE : self::DEFAULT_TARGET_ROUTE, $locale));
    }

    /**
     * No sign-in since the address was confirmed. `lastLoginAt` alone is not
     * that: it is the dormancy clock, and on some accounts it holds a time
     * from before the confirmation (Version20260828130000 starts it at
     * `created_at`, and older accounts could sign in unconfirmed). A clock
     * older than the confirmation counts as no sign-in yet.
     *
     * @see docs/specs/account-and-auth.md §8
     */
    private static function isFirstSinceConfirmation(User $user): bool
    {
        $last = $user->getLastLoginAt();
        if (null === $last) {
            return true;
        }
        $confirmed = $user->getEmailVerifiedAt();

        return null !== $confirmed && $last < $confirmed;
    }

    private function localizedUrl(string $route, ?string $locale): string
    {
        $params = null !== $locale ? ['_locale' => $locale] : [];

        return $this->urlGenerator->generate($route, $params);
    }
}
