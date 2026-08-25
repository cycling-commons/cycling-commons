<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A half-authenticated session writes nothing (account-and-auth.md §4).
 *
 * Between the password and the second factor the session holds a
 * TwoFactorToken whose `getUser()` returns the real User. Controllers that
 * gate on `getUser() instanceof User` therefore look, from the inside, exactly
 * as they do after a completed login, and a 2026-08-25 security scan read that
 * as state-changing POSTs being reachable mid-interstitial.
 *
 * They are not. scheb's TwoFactorAccessListener refuses every path that is not
 * the interstitial itself or explicitly PUBLIC_ACCESS, and bounces it to /2fa
 * before any controller runs. That is a bundle behaviour rather than anything
 * this app wrote down, which is why it is pinned here: it is invisible in the
 * controllers it protects, and access_control gains a PUBLIC_ACCESS line most
 * months (see security.yaml, where several exist purely for cacheability).
 * Adding one for a state-changing route would quietly hand it to half-logged-in
 * sessions.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction.
 */
final class TwoFactorInterstitialLockdownTest extends WebTestCase
{
    private const string EMAIL = 'half-auth@example.com';
    private const string PASSWORD = 'hunter2secure!';
    private const string CSRF = 'ccTestCsrfTokenValue0123456789';

    private function enrolledRider(): void
    {
        $container = static::getContainer();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var TotpAuthenticatorInterface $totp */
        $totp = $container->get(TotpAuthenticatorInterface::class);

        $user = new User();
        $user->setEmail(self::EMAIL);
        $user->setDisplayName('Half Auth');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $user->setTotpSecret($totp->generateSecret());
        $user->setTwoFaEnabled(true);

        $em->persist($user);
        $em->flush();
    }

    /** Password step only: the session is now mid-2FA. */
    private function halfAuthenticate(KernelBrowser $client): void
    {
        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Sign in')->form([
            '_username' => self::EMAIL,
            '_password' => self::PASSWORD,
        ]));
        self::assertResponseRedirects('/2fa', null, 'the password step must land on the interstitial');
    }

    private function storedTheme(): ?string
    {
        /** @var Connection $conn */
        $conn = static::getContainer()->get('doctrine.dbal.default_connection');
        $value = $conn->fetchOne('SELECT map_theme FROM users WHERE email = ?', [self::EMAIL]);

        return \is_string($value) ? $value : null;
    }

    public function testProfileWritesAreRefusedUntilTheSecondFactorIsGiven(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->enrolledRider();

        $before = $this->storedTheme();
        self::assertNotSame('light', $before, 'the fixture must not already hold the value under test');

        $this->halfAuthenticate($client);

        $client->getCookieJar()->set(new Cookie('csrf-token_'.self::CSRF, 'csrf-token', null, '/', 'localhost'));
        foreach ([
            '/map/theme' => ['theme' => 'light', '_token' => self::CSRF],
            '/map/view-mode' => ['mode' => 'everything', '_token' => self::CSRF],
        ] as $path => $body) {
            $client->request('POST', $path, $body);
            self::assertResponseRedirects(
                'http://localhost/2fa',
                null,
                sprintf('%s must be bounced back to the interstitial, not executed', $path),
            );
        }

        self::assertSame($before, $this->storedTheme(), 'a half-authenticated session wrote to the profile');
    }
}
