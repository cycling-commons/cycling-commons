<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Auth;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Security firewall — login page rendering, anonymous redirect, and form-login flow.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back transaction
 * so users created here never persist to subsequent tests or runs.
 */
final class SecurityTest extends WebTestCase
{
    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Create a verified user in the DB and return their plain-text password. */
    private function createVerifiedUser(string $email, string $plain, string $role = 'ROLE_USER'): string
    {
        $container = static::getContainer();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Test Rider');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles('ROLE_USER' === $role ? [] : [$role]);
        $user->setPassword($hasher->hashPassword($user, $plain));

        $em->persist($user);
        $em->flush();

        return $plain;
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    public function testLoginPageRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
        self::assertSelectorExists('input[name="_username"]');
        self::assertSelectorExists('input[name="_password"]');
        self::assertSelectorExists('input[name="_csrf_token"]');
    }

    public function testAnonymousProfileRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/profile');

        self::assertResponseRedirects('/login', 302);
    }

    public function testValidLoginRedirectsToAccountDashboard(): void
    {
        $client = static::createClient();

        $email = 'rider-test@example.com';
        $plain = $this->createVerifiedUser($email, 'hunter2secure!');

        $crawler = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => $plain,
        ]);
        $client->submit($form);

        // form_login posts to check_path (same /login); LoginSuccessHandler then
        // sends a plain user to the account dashboard (/profile), not home.
        self::assertResponseRedirects('/profile');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    /**
     * A rider who comes back on the remember-me cookie has signed in, as far as
     * the dormancy ladder is concerned. The login form's success handler never
     * runs for them, so RememberedLoginListener stamps the clock instead and
     * clears any warning already sent (account-and-auth.md §6.5).
     */
    public function testComingBackOnTheRememberMeCookieCountsAsASignIn(): void
    {
        $client = static::createClient();

        $email = 'remembered@example.com';
        $plain = $this->createVerifiedUser($email, 'hunter2secure!');

        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => $plain,
        ]);
        $form['_remember_me']->tick();
        $client->submit($form);
        self::assertResponseRedirects('/profile');
        self::assertNotNull($client->getCookieJar()->get('REMEMBERME'), 'the remember-me cookie was set');

        // Age the clock and leave a warning on file, then drop the session so
        // the next request is signed in by the cookie alone.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);
        $user->recordLogin(new \DateTimeImmutable('-400 days'));
        $user->recordDormancyNotice('m12', new \DateTimeImmutable('-30 days'));
        $em->flush();
        $client->getCookieJar()->expire('MOCKSESSID');

        $client->request('GET', '/profile');
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);
        self::assertNotNull($user->getLastLoginAt());
        self::assertGreaterThan(new \DateTimeImmutable('-1 hour'), $user->getLastLoginAt());
        self::assertFalse($user->dormancyNoticesSent()['m12'], 'coming back clears the warning');
    }

    public function testInvalidPasswordShowsError(): void
    {
        $client = static::createClient();

        $email = 'bad-pass@example.com';
        $this->createVerifiedUser($email, 'correcthorse!');

        $crawler = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => 'wrongpassword',
        ]);
        $client->submit($form);

        // Firewall rejects → redirects back to /login
        self::assertResponseRedirects('/login');
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-error');
    }
}
