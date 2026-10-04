<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Security\PasswordReauthBudget;
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
            // Stored as hashes (matching real storage); plaintext codes are entered at login.
            $user->setBackupCodes(array_map(User::hashBackupCode(...), $backupCodes));
        }

        $em->persist($user);
        $em->flush();

        return ['plain' => $plain, 'secret' => $secret];
    }

    /** Compute the current valid TOTP code for a raw (base32) secret, matching the entity config. */
    /**
     * A TOTP code that will still be valid when the server checks it.
     *
     * `leeway` is 1 since 2026-08-30, so one window either side is accepted and
     * an ordinary boundary crossing no longer fails. This still waits out the
     * tail of a window rather than racing it, for two reasons: the wait is what
     * makes the result independent of how busy the machine is, and a test that
     * leans on the leeway is a test that stops covering the leeway. Green on a
     * laptop and red in CI is exactly what this removes.
     *
     * The wait only happens in the last few seconds of a window, which is rare,
     * and it is bounded by that.
     */
    private function currentTotpCode(string $secret): string
    {
        $period = 30;
        /* Enough for a form submit through the kernel, with room to spare. */
        $needed = 5;

        $left = $period - (time() % $period);
        if ($left < $needed) {
            usleep($left * 1_000_000 + 100_000);
        }

        return TOTP::create($secret, $period, 'sha1', 6)->now();
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

    /** Sign in with "Stay signed in" ticked, without following the redirect. */
    private function submitRememberedLogin(KernelBrowser $client, string $email, string $plain): void
    {
        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => $plain,
        ]);
        $form['_remember_me']->tick();
        $client->submit($form);
    }

    /** Pass scheb's interstitial with a current code, for an account that has two-factor. */
    private function completeTwoFactorLogin(KernelBrowser $client, string $email, string $plain, string $secret): void
    {
        $this->submitLogin($client, $email, $plain);
        self::assertResponseRedirects('/2fa');
        $crawler = $client->followRedirect();
        $form = $crawler->selectButton('Verify')->form();
        $form['_auth_code'] = $this->currentTotpCode($secret);
        $client->submit($form);
        self::assertResponseRedirects();
    }

    /**
     * Post the setup form with a valid code for the new secret on the page.
     *
     * @param array<string, string> $proof the owner checks: currentCode, currentPassword
     */
    private function submitSetup(KernelBrowser $client, array $proof): void
    {
        $crawler = $client->request('GET', '/2fa/setup');
        self::assertResponseIsSuccessful();
        $secret = trim($crawler->filter('code.secret')->text());
        $form = $crawler->selectButton('Verify & enable')->form();
        foreach ($proof as $field => $value) {
            $form['two_factor_setup['.$field.']'] = $value;
        }
        $form['two_factor_setup[code]'] = $this->currentTotpCode($secret);
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
        $client->request('GET', '/account/contributions');
        self::assertResponseRedirects();
        self::assertStringNotContainsString('/account/contributions', (string) $client->getResponse()->headers->get('Location'));

        // Submit a valid TOTP code at the check path.
        $code = $this->currentTotpCode($data['secret']);
        $form = $crawler->selectButton('Verify')->form();
        $form['_auth_code'] = $code;
        $client->submit($form);

        // Fully authenticated → redirected away from the interstitial.
        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringNotContainsString('/2fa', $location);

        // Can now reach a ROLE_USER area without bouncing to /2fa or /login.
        $client->request('GET', '/account/contributions');
        $status = $client->getResponse()->getStatusCode();
        // /account/contributions may 404 (no controller yet) but must NOT redirect to /login or /2fa.
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
        $form = $crawler->selectButton('Verify')->form();
        $form['_auth_code'] = $backupCode;
        $client->submit($form);
        self::assertResponseRedirects();
        self::assertStringNotContainsString('/2fa', (string) $client->getResponse()->headers->get('Location'));

        // The used backup code is consumed (no longer stored on the user).
        $user = $this->fetchUser($email);
        self::assertNotContains(User::hashBackupCode($backupCode), $user->getBackupCodes(), 'Used backup code must be invalidated.');
        self::assertContains(User::hashBackupCode('ffff-9999'), $user->getBackupCodes(), 'Unused backup codes remain.');

        // Reuse must fail: a fresh browser session + the same code does not complete 2FA.
        $client->restart();
        $this->submitLogin($client, $email, $data['plain']);
        self::assertResponseRedirects('/2fa');
        $crawler2 = $client->followRedirect();
        $form2 = $crawler2->selectButton('Verify')->form();
        $form2['_auth_code'] = $backupCode;
        $client->submit($form2);
        // Rejected → bounced back to the interstitial (still 2FA in progress).
        self::assertResponseRedirects('/2fa');
    }

    public function testTotpSecretIsEncryptedAtRestAndBackupCodesHashed(): void
    {
        static::createClient();

        $email = '2fa-enc@example.com';
        $data = $this->createUser($email, 'hunter2secure!', withTotp: true, backupCodes: ['aaaa-1111']);
        $secret = $data['secret'];
        self::assertIsString($secret);

        // Raw DB column must NOT hold the plaintext seed — it is AES-GCM ciphertext (base64).
        $conn = static::getContainer()->get('doctrine.dbal.default_connection');
        $rawSecret = $conn->fetchOne('SELECT totp_secret FROM users WHERE email = ?', [$email]);
        self::assertIsString($rawSecret);
        self::assertNotSame($secret, $rawSecret, 'TOTP secret must be encrypted at rest.');
        self::assertNotFalse(base64_decode($rawSecret, true), 'Stored value should be base64 ciphertext.');

        // Through the ORM it decrypts transparently back to the original secret.
        $user = $this->fetchUser($email);
        self::assertSame($secret, $user->getTotpSecret());

        // Backup codes are stored hashed, never in plaintext.
        self::assertContains(User::hashBackupCode('aaaa-1111'), $user->getBackupCodes());
        self::assertNotContains('aaaa-1111', $user->getBackupCodes());
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

        // No interstitial, no forced setup → the first-sign-in landing, the profile (/account/settings).
        self::assertResponseRedirects('/account/settings');
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringNotContainsString('/2fa', $location);
    }

    // ── Enforcer gate tests (TwoFactorSetupEnforcer) ────────────────────────

    /**
     * ROLE_CURATOR without a TOTP secret must be redirected to /2fa/setup on ANY
     * subsequent request, not only immediately after login (gate test).
     *
     * Without the TwoFactorSetupEnforcer subscriber this test FAILS: the user is fully
     * authenticated and can reach /account/contributions directly. With the subscriber it PASSES.
     */
    public function testElevatedRoleWithoutSecretIsBlockedOnEveryRequest(): void
    {
        $client = static::createClient();

        $email = 'curator-gate@example.com';
        $data = $this->createUser($email, 'hunter2secure!', role: 'ROLE_CURATOR', withTotp: false);

        // Log in — LoginSuccessHandler redirects to /2fa/setup (advisory).
        $this->submitLogin($client, $email, $data['plain']);
        self::assertResponseRedirects('/2fa/setup');

        // Attempt to navigate directly to /account/contributions, bypassing the login redirect.
        $client->request('GET', '/account/contributions');
        self::assertTrue($client->getResponse()->isRedirection(), 'Should be redirected away from /account/contributions');
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/2fa/setup', $location, 'Must redirect to 2FA setup, not elsewhere');
        self::assertStringNotContainsString('/account/contributions', $location, 'Must NOT reach /account/contributions without 2FA');
    }

    /**
     * ROLE_CURATOR who HAS completed 2FA setup (totpSecret set, twoFaEnabled) and is fully
     * authenticated must NOT be redirected to /2fa/setup — they have already enrolled.
     */
    public function testElevatedRoleWithCompletedTwoFactorCanReachProfile(): void
    {
        $client = static::createClient();

        $email = 'curator-enrolled@example.com';
        $data = $this->createUser($email, 'hunter2secure!', role: 'ROLE_CURATOR', withTotp: true);

        // Login → scheb intercepts → 2FA interstitial.
        $this->submitLogin($client, $email, $data['plain']);
        self::assertResponseRedirects('/2fa');
        $crawler = $client->followRedirect();

        // Complete the TOTP challenge.
        self::assertIsString($data['secret']);
        $form = $crawler->selectButton('Verify')->form();
        $form['_auth_code'] = $this->currentTotpCode($data['secret']);
        $client->submit($form);
        self::assertResponseRedirects();

        // Now fully authenticated + enrolled — must NOT be bounced to setup.
        $client->request('GET', '/account/contributions');
        if ($client->getResponse()->isRedirection()) {
            $loc = (string) $client->getResponse()->headers->get('Location');
            self::assertStringNotContainsString('/2fa/setup', $loc, 'Enrolled user must not be redirected to setup');
        } else {
            // 200/403/404 all acceptable — anything but a setup redirect.
            self::assertContains($client->getResponse()->getStatusCode(), [200, 403, 404]);
        }
    }

    /**
     * A plain ROLE_USER without 2FA must NOT be forced to /2fa/setup —
     * 2FA is optional for non-elevated roles.
     */
    public function testPlainUserWithoutTwoFactorCanReachProfile(): void
    {
        $client = static::createClient();

        $email = 'plain-gate@example.com';
        $data = $this->createUser($email, 'hunter2secure!', role: 'ROLE_USER', withTotp: false);

        $this->submitLogin($client, $email, $data['plain']);
        $client->followRedirect(); // follow → home

        $client->request('GET', '/account/contributions');
        if ($client->getResponse()->isRedirection()) {
            $loc = (string) $client->getResponse()->headers->get('Location');
            self::assertStringNotContainsString('/2fa/setup', $loc, 'Plain user must not be sent to 2FA setup');
        } else {
            self::assertContains($client->getResponse()->getStatusCode(), [200, 403, 404]);
        }
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

        $form = $crawler->selectButton('Verify & enable')->form();
        $form['two_factor_setup[code]'] = $this->currentTotpCode($secret);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('ul.codes li');

        // The account's own address hears of it at once.
        self::assertEmailCount(1, null, 'the two-factor notice');
        $mail = self::getMailerMessage();
        self::assertNotNull($mail);
        self::assertEmailAddressContains($mail, 'to', $email);
        self::assertEmailHeaderSame($mail, 'subject', 'Two-factor sign-in is now on for your account');
        self::assertStringContainsString('Change my password', (string) $mail->getHtmlBody());
        self::assertStringNotContainsString('twofactor_email.', (string) $mail->getHtmlBody(), 'every line translated');

        $user = $this->fetchUser($email);
        self::assertTrue($user->isTwoFaEnabled());
        self::assertSame($secret, $user->getTotpSecret());
        self::assertNotEmpty($user->getBackupCodes());

        // #13: each shown backup code must carry ≥80 bits of entropy (20 hex
        // digits, dashes aside) — not the old 32-bit (8 hex) codes.
        foreach ($crawler->filter('ul.codes li') as $li) {
            $hex = str_replace('-', '', trim($li->textContent));
            self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $hex);
            self::assertGreaterThanOrEqual(20, \strlen($hex), 'backup code must have ≥80 bits of entropy');
        }
    }

    /**
     * #31: an elevated user who has a TOTP secret but has NOT enabled 2FA
     * (twoFaEnabled=false) is never challenged at login, so the enforcer must
     * still route them to /2fa/setup — matching scheb's actual challenge
     * predicate, not the weaker "has a secret".
     */
    public function testElevatedRoleWithSecretButTwoFaDisabledIsStillSentToSetup(): void
    {
        $client = static::createClient();

        $email = 'curator-halfsetup@example.com';
        $this->createUser($email, 'hunter2secure!', role: 'ROLE_CURATOR', withTotp: true);

        // Flip twoFaEnabled off while keeping the secret (the vulnerable state).
        $user = $this->fetchUser($email);
        $user->setTwoFaEnabled(false);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        // A twoFaEnabled=false user is NOT challenged, so log in completes fully.
        $this->submitLogin($client, $email, 'hunter2secure!');
        $client->request('GET', '/account/contributions');
        self::assertTrue($client->getResponse()->isRedirection());
        self::assertStringContainsString('/2fa/setup', (string) $client->getResponse()->headers->get('Location'));
    }

    /**
     * #16: an anonymous request to a public, cacheable data endpoint must not
     * start a session — the enforcer must not read the token for it.
     */
    public function testPublicCatalogEndpointDoesNotStartASession(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map/catalog/stamps.json');

        self::assertResponseIsSuccessful();
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            self::assertStringNotContainsStringIgnoringCase('SESS', $cookie->getName(), 'a cacheable public endpoint must not set a session cookie');
        }
    }

    /**
     * A remember-me cookie alone is a signed-in request too: a rider promoted
     * to curator after ticking "Stay signed in" comes back on the cookie, with
     * no session, and is sent to setup like anyone else.
     */
    public function testARememberMeCookieAloneIsStillSentToSetup(): void
    {
        $client = static::createClient();
        $email = 'promoted-curator@example.com';
        $this->createUser($email, 'hunter2secure!');

        $this->submitRememberedLogin($client, $email, 'hunter2secure!');
        self::assertNotNull($client->getCookieJar()->get('REMEMBERME'), 'a rider may stay signed in');

        $user = $this->fetchUser($email);
        $user->setRoles(['ROLE_CURATOR']);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->getCookieJar()->expire('MOCKSESSID');

        $client->request('GET', '/moderate');
        self::assertResponseRedirects('/2fa/setup');
    }

    /** No seven-day cookie before there is a second factor behind it. */
    public function testNoRememberMeCookieIsSetBeforeTwoFactorIsSetUp(): void
    {
        $client = static::createClient();
        $email = 'new-curator@example.com';
        $this->createUser($email, 'hunter2secure!', role: 'ROLE_CURATOR');

        $this->submitRememberedLogin($client, $email, 'hunter2secure!');
        self::assertResponseRedirects('/2fa/setup');
        self::assertNull($client->getCookieJar()->get('REMEMBERME'));
    }

    /** Replacing two-factor proves the old one first: a wrong code changes nothing, a current one does. */
    public function testReplacingTwoFactorNeedsACodeFromTheOldOne(): void
    {
        $client = static::createClient();
        $email = 'replace-totp@example.com';
        $data = $this->createUser($email, 'hunter2secure!', withTotp: true);
        self::assertIsString($data['secret']);
        $this->completeTwoFactorLogin($client, $email, $data['plain'], $data['secret']);

        $crawler = $client->request('GET', '/2fa/setup');
        self::assertCount(1, $crawler->filter('input[name="two_factor_setup[currentCode]"]'));
        self::assertCount(0, $crawler->filter('input[name="two_factor_setup[currentPassword]"]'), 'a full sign-in is not asked for the password again');

        $this->submitSetup($client, ['currentCode' => 'not-a-code']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-error', 'does not match your current app');
        self::assertEmailCount(0, null, 'nothing changed, so nothing to tell');
        self::assertSame($data['secret'], $this->fetchUser($email)->getTotpSecret(), 'a wrong code replaces nothing');

        $this->submitSetup($client, ['currentCode' => $this->currentTotpCode($data['secret'])]);
        self::assertSelectorExists('ul.codes li');
        self::assertEmailCount(1, null, 'the two-factor notice');
        $mail = self::getMailerMessage();
        self::assertNotNull($mail);
        self::assertEmailAddressContains($mail, 'to', $email);
        self::assertEmailHeaderSame($mail, 'subject', 'Your two-factor sign-in moved to a new app');
        self::assertNotSame($data['secret'], $this->fetchUser($email)->getTotpSecret());
    }

    /** Somebody who lost the app proves ownership with a backup code instead. */
    public function testABackupCodeAlsoProvesTheOldFactor(): void
    {
        $client = static::createClient();
        $email = 'replace-backup@example.com';
        $data = $this->createUser($email, 'hunter2secure!', withTotp: true, backupCodes: ['aaaa-bbbb-cccc-dddd-eeee']);
        self::assertIsString($data['secret']);
        $this->completeTwoFactorLogin($client, $email, $data['plain'], $data['secret']);

        $this->submitSetup($client, ['currentCode' => 'aaaa-bbbb-cccc-dddd-eeee']);
        self::assertSelectorExists('ul.codes li');
        $user = $this->fetchUser($email);
        self::assertNotSame($data['secret'], $user->getTotpSecret());
        self::assertFalse($user->isBackupCode('aaaa-bbbb-cccc-dddd-eeee'), 'enrolling issues a fresh set of backup codes');
    }

    /** A remember-me session confirms the password before it enrols anything. */
    public function testARememberedSessionConfirmsThePasswordBeforeEnrolling(): void
    {
        $client = static::createClient();
        $email = 'remembered-enrol@example.com';
        $this->createUser($email, 'hunter2secure!');
        $this->submitRememberedLogin($client, $email, 'hunter2secure!');
        $client->getCookieJar()->expire('MOCKSESSID');

        $crawler = $client->request('GET', '/2fa/setup');
        self::assertCount(1, $crawler->filter('input[name="two_factor_setup[currentPassword]"]'));

        $this->submitSetup($client, ['currentPassword' => 'not-my-password']);
        self::assertSelectorTextContains('.alert-error', 'Current password is incorrect');
        self::assertFalse($this->fetchUser($email)->isTwoFaEnabled());

        $this->submitSetup($client, ['currentPassword' => 'hunter2secure!']);
        self::assertSelectorExists('ul.codes li');
        self::assertTrue($this->fetchUser($email)->isTwoFaEnabled());
    }

    /** The owner checks spend the shared password budget: past it, even the right code is refused. */
    public function testOwnerChecksStopWhenThePasswordBudgetIsSpent(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $budget = PasswordReauthBudget::install(static::getContainer());
        $email = 'replace-budget@example.com';
        $data = $this->createUser($email, 'hunter2secure!', withTotp: true);
        self::assertIsString($data['secret']);
        $this->completeTwoFactorLogin($client, $email, $data['plain'], $data['secret']);

        for ($i = 0; $i < $budget; ++$i) {
            $this->submitSetup($client, ['currentCode' => 'not-a-code']);
            self::assertSelectorTextContains('.alert-error', 'does not match your current app');
        }

        $this->submitSetup($client, ['currentCode' => $this->currentTotpCode($data['secret'])]);
        self::assertSelectorTextContains('.form-errors', 'Too many tries');
        self::assertSame($data['secret'], $this->fetchUser($email)->getTotpSecret());
    }

    /** #18: /2fa/setup with no authenticated user redirects to login, never 500s. */
    public function testAnonymousSetupRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/2fa/setup');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }
}
