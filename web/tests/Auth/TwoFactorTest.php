<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Tests\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * TOTP two-factor authentication: interstitial challenge, TOTP + backup-code completion,
 * single-use backup-code invalidation, and elevated-role enrolment enforcement.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back transaction.
 */
final class TwoFactorTest extends WebTestCase
{
    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Create a verified user, optionally with 2FA enabled (secret + backup codes), and return
     * the plain password plus the raw TOTP secret (or null when 2FA is off).
     *
     * @param list<string> $backupCodes
     *
     * @return array{plain: string, secret: ?string}
     */
    private function createUser(
        string $email,
        string $plain,
        string $role = 'ROLE_USER',
        bool $withTotp = false,
        array $backupCodes = [],
    ): array {
        $container = static::getContainer();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $secret = null;
        if ($withTotp) {
            /** @var TotpAuthenticatorInterface $totp */
            $totp = $container->get(TotpAuthenticatorInterface::class);
            $secret = $totp->generateSecret();
        }

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Test Rider');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles('ROLE_USER' === $role ? [] : [$role]);
        $user->setPassword($hasher->hashPassword($user, $plain));

        if ($withTotp && null !== $secret) {
            $user->setTotpSecret($secret);
            $user->setTwoFaEnabled(true);
            $user->setBackupCodes($backupCodes);
        }

        $em->persist($user);
        $em->flush();

        return ['plain' => $plain, 'secret' => $secret];
    }

    /** Compute the current valid TOTP code for a raw (base32) secret, matching the entity config. */
    private function currentTotpCode(string $secret): string
    {
        return TOTP::create($secret, 30, 'sha1', 6)->now();
    }

    /** Submit the primary login form. Returns the client after submission (before following redirects). */
    private function submitLogin(KernelBrowser $client, string $email, string $plain): void
    {
        $crawler = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => $plain,
        ]);
        $client->submit($form);
    }

    private function fetchUser(string $email): User
    {
        /** @var UserRepository $repo */
        $repo = static::getContainer()->get(UserRepository::class);
        $user = $repo->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    public function testTwoFactorUserHitsInterstitialThenCompletesWithTotp(): void
    {
        $client = static::createClient();

        $email = '2fa-totp@example.com';
        $data = $this->createUser($email, 'hunter2secure!', withTotp: true);
        self::assertIsString($data['secret']);

        // Primary login → scheb intercepts → 2FA interstitial.
        $this->submitLogin($client, $email, $data['plain']);
        self::assertResponseRedirects('/2fa');
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="_auth_code"]');

        // Not yet able to reach a ROLE_USER area.
        $client->request('GET', '/profile');
        self::assertResponseRedirects();
        self::assertStringNotContainsString('/profile', (string) $client->getResponse()->headers->get('Location'));

        // Submit a valid TOTP code at the check path.
        $code = $this->currentTotpCode($data['secret']);
        $form = $crawler->filter('form')->form();
        $form['_auth_code'] = $code;
        $client->submit($form);

        // Fully authenticated → redirected away from the interstitial.
        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringNotContainsString('/2fa', $location);

        // Can now reach a ROLE_USER area without bouncing to /2fa or /login.
        $client->request('GET', '/profile');
        $status = $client->getResponse()->getStatusCode();
        // /profile may 404 (no controller yet) but must NOT redirect to /login or /2fa.
        if ($client->getResponse()->isRedirection()) {
            $loc = (string) $client->getResponse()->headers->get('Location');
            self::assertStringNotContainsString('/login', $loc);
            self::assertStringNotContainsString('/2fa', $loc);
        } else {
            self::assertContains($status, [200, 403, 404]);
        }
    }

    public function testBackupCodeCompletesTwoFactorAndIsThenInvalidated(): void
    {
        $client = static::createClient();

        $email = '2fa-backup@example.com';
        $backupCode = 'abcd-1234';
        $data = $this->createUser($email, 'hunter2secure!', withTotp: true, backupCodes: [$backupCode, 'ffff-9999']);

        // Login → interstitial.
        $this->submitLogin($client, $email, $data['plain']);
        self::assertResponseRedirects('/2fa');
        $crawler = $client->followRedirect();

        // Complete 2FA with the backup code.
        $form = $crawler->filter('form')->form();
        $form['_auth_code'] = $backupCode;
        $client->submit($form);
        self::assertResponseRedirects();
        self::assertStringNotContainsString('/2fa', (string) $client->getResponse()->headers->get('Location'));

        // The used backup code is consumed (no longer stored on the user).
        $user = $this->fetchUser($email);
        self::assertNotContains($backupCode, $user->getBackupCodes(), 'Used backup code must be invalidated.');
        self::assertContains('ffff-9999', $user->getBackupCodes(), 'Unused backup codes remain.');

        // Reuse must fail: a fresh browser session + the same code does not complete 2FA.
        $client->restart();
        $this->submitLogin($client, $email, $data['plain']);
        self::assertResponseRedirects('/2fa');
        $crawler2 = $client->followRedirect();
        $form2 = $crawler2->filter('form')->form();
        $form2['_auth_code'] = $backupCode;
        $client->submit($form2);
        // Rejected → bounced back to the interstitial (still 2FA in progress).
        self::assertResponseRedirects('/2fa');
    }

    public function testElevatedRoleWithoutSecretIsRedirectedToSetup(): void
    {
        $client = static::createClient();

        $email = 'curator-no-2fa@example.com';
        $data = $this->createUser($email, 'hunter2secure!', role: 'ROLE_CURATOR', withTotp: false);

        $this->submitLogin($client, $email, $data['plain']);

        // success_handler forces enrolment.
        self::assertResponseRedirects('/2fa/setup');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('img.qr');
        self::assertSelectorExists('input[name="two_factor_setup[code]"]');
    }

    public function testPlainUserWithoutTwoFactorLogsInNormally(): void
    {
        $client = static::createClient();

        $email = 'plain-user@example.com';
        $data = $this->createUser($email, 'hunter2secure!', role: 'ROLE_USER', withTotp: false);

        $this->submitLogin($client, $email, $data['plain']);

        // No interstitial, no forced setup → default target (home).
        self::assertResponseRedirects('/');
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringNotContainsString('/2fa', $location);
    }

    public function testSetupFlowEnablesTwoFactorAndShowsBackupCodes(): void
    {
        $client = static::createClient();

        $email = 'setup-flow@example.com';
        $data = $this->createUser($email, 'hunter2secure!', role: 'ROLE_USER', withTotp: false);

        // Log in normally, then visit /2fa/setup as a fully authenticated user.
        $this->submitLogin($client, $email, $data['plain']);
        $client->followRedirect();

        $crawler = $client->request('GET', '/2fa/setup');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('code.secret');

        // Read the pending secret from the rendered manual key and confirm with a valid code.
        $secret = trim($crawler->filter('code.secret')->text());
        self::assertNotSame('', $secret);

        $form = $crawler->filter('form')->form();
        $form['two_factor_setup[code]'] = $this->currentTotpCode($secret);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('ul.codes li');

        $user = $this->fetchUser($email);
        self::assertTrue($user->isTwoFaEnabled());
        self::assertSame($secret, $user->getTotpSecret());
        self::assertNotEmpty($user->getBackupCodes());
    }
}
