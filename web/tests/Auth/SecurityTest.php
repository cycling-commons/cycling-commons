<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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

    public function testValidLoginRedirectsToHome(): void
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

        // form_login posts to check_path (same /login), then redirects to default_target_path (home = /)
        self::assertResponseRedirects();
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        // Should land on home (/), not back on /login
        self::assertStringNotContainsString('/login', (string) $client->getRequest()->getUri());
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
