<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Auth;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The 2FA interstitial is brute-force bounded (account-and-auth.md §3).
 *
 * Why this file exists. A 2026-08-25 security scan reported /2fa_login_check as
 * unthrottled: a phished password would buy unlimited guesses at a six-digit
 * code, on exactly the elevated accounts 2FA is mandatory for. Reproducing it
 * end to end showed the opposite. LoginThrottleListener is wired to
 * LoginFailureEvent and CheckPassportEvent, which Symfony's authenticator
 * manager dispatches for EVERY authenticator on the firewall, scheb's
 * TwoFactorAuthenticator included. So a wrong code already counts against the
 * per-account budget, and the fifth one locks the account for 15 minutes.
 *
 * That coverage was incidental, though: nothing named it and nothing tested it,
 * so narrowing LoginThrottleListener to the password step (an entirely
 * reasonable-looking refactor) would silently open the hole the scan described.
 * This file makes the behaviour deliberate.
 *
 * `disableReboot()` throughout: the counters live in the database, but the
 * session must survive from the interstitial to the next POST, and a rebooted
 * test kernel does not carry one.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction.
 */
final class TwoFactorBruteForceTest extends WebTestCase
{
    private const string PASSWORD = 'hunter2secure!';

    /** @return array{secret: string} */
    private function createTwoFactorUser(string $email): array
    {
        $container = static::getContainer();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var TotpAuthenticatorInterface $totp */
        $totp = $container->get(TotpAuthenticatorInterface::class);

        $secret = $totp->generateSecret();

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Throttle Rider');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $user->setTotpSecret($secret);
        $user->setTwoFaEnabled(true);

        $em->persist($user);
        $em->flush();

        return ['secret' => $secret];
    }

    private function lockedUntil(string $email): ?string
    {
        /** @var Connection $conn */
        $conn = static::getContainer()->get('doctrine.dbal.default_connection');
        $value = $conn->fetchOne('SELECT locked_until FROM users WHERE email = ?', [$email]);

        return \is_string($value) ? $value : null;
    }

    /**
     * Five wrong codes lock the account, and the SIXTH attempt is refused even
     * when the code is right.
     *
     * The correct-code assertion is the one that carries the test. Feeding only
     * wrong codes cannot tell a brute-force gate apart from the code check
     * doing its ordinary job, so such a test would still pass with every gate
     * deleted. A valid TOTP being turned away can only be the lockout.
     */
    public function testFiveWrongCodesLockTheAccountAndTheRightCodeIsThenRefused(): void
    {
        $email = '2fa-brute@example.com';
        $client = static::createClient();
        $client->disableReboot();
        $secret = $this->createTwoFactorUser($email)['secret'];

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => self::PASSWORD,
        ]));
        self::assertResponseRedirects('/2fa');
        $crawler = $client->followRedirect();

        self::assertNull($this->lockedUntil($email), 'a fresh account starts unlocked');

        for ($i = 1; $i <= 5; ++$i) {
            $form = $crawler->selectButton('Verify')->form();
            $form['_auth_code'] = '000000';
            $client->submit($form);
            self::assertResponseRedirects('/2fa', null, "wrong code {$i} keeps the session on the interstitial");
            $crawler = $client->followRedirect();
        }

        self::assertNotNull($this->lockedUntil($email), 'the fifth wrong code must lock the account');

        // The real code, and still refused. This is the whole point.
        $form = $crawler->selectButton('Verify')->form();
        $form['_auth_code'] = TOTP::create($secret, 30, 'sha1', 6)->now();
        $client->submit($form);
        self::assertResponseRedirects('/2fa', null, 'a locked account must not complete 2FA with a valid code');

        // Nothing behind the gate opened either.
        $client->request('GET', '/account/contributions');
        self::assertResponseRedirects();
        self::assertStringNotContainsString(
            '/account/contributions',
            (string) $client->getResponse()->headers->get('Location'),
        );
    }

    /**
     * The budget is per account: attacking one rider must not lock another out
     * of their own interstitial.
     */
    public function testTheLockoutIsPerAccount(): void
    {
        $victim = '2fa-brute-a@example.com';
        $bystander = '2fa-brute-b@example.com';

        $client = static::createClient();
        $client->disableReboot();
        $this->createTwoFactorUser($victim);
        $bystanderSecret = $this->createTwoFactorUser($bystander)['secret'];

        // Burn the victim's budget.
        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $victim,
            '_password' => self::PASSWORD,
        ]));
        $crawler = $client->followRedirect();
        for ($i = 1; $i <= 5; ++$i) {
            $form = $crawler->selectButton('Verify')->form();
            $form['_auth_code'] = '000000';
            $client->submit($form);
            $crawler = $client->followRedirect();
        }
        self::assertNotNull($this->lockedUntil($victim));
        self::assertNull($this->lockedUntil($bystander), 'the bystander is untouched');

        // The bystander signs in normally, right code and all.
        $client->restart();
        $client->disableReboot();
        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $bystander,
            '_password' => self::PASSWORD,
        ]));
        self::assertResponseRedirects('/2fa');
        $crawler = $client->followRedirect();

        $form = $crawler->selectButton('Verify')->form();
        $form['_auth_code'] = TOTP::create($bystanderSecret, 30, 'sha1', 6)->now();
        $client->submit($form);

        self::assertResponseRedirects();
        self::assertStringNotContainsString(
            '/2fa',
            (string) $client->getResponse()->headers->get('Location'),
            'a bystander with the right code completes 2FA normally',
        );
    }
}
