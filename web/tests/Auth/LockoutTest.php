<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Tests\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Per-account brute-force lockout (LoginThrottleListener).
 *
 * Covers three contractual guarantees:
 *   (a) After 5 failed attempts: failedLoginAttempts ≥ 5, lockedUntil is set,
 *       and isLocked() returns true.
 *   (b) While locked: a login with the *correct* password is rejected with the
 *       lockout message, not a credential error and not a success redirect.
 *   (c) After a successful login (post unlock): failedLoginAttempts is reset to 0
 *       and lockedUntil is set back to null.
 *
 * Throttle note: config/packages/test/security.yaml raises login_throttling to
 * max_attempts=100 so Symfony's per-IP throttle does NOT interfere.  The
 * per-account hard lock at 5 is the sole mechanism under test.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction so users created here never persist to other tests.
 */
final class LockoutTest extends WebTestCase
{
    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Create a verified user and return their plain-text password. */
    private function createVerifiedUser(string $email, string $plain): string
    {
        $container = static::getContainer();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Lock Test Rider');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPassword($hasher->hashPassword($user, $plain));

        $em->persist($user);
        $em->flush();

        return $plain;
    }

    /**
     * Submit the login form with the given credentials using the current client.
     * Requires that createClient() has already been called in the test method.
     */
    private function submitLogin(string $email, string $password): void
    {
        $client = static::getClient();
        $crawler = $client->request('GET', '/login');

        $form = $crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => $password,
        ]);
        $client->submit($form);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * (a) Five failed attempts lock the account.
     *
     * We inspect the User entity directly after the fifth bad login so the
     * assertion is on database state, not on HTTP behaviour.
     */
    public function testFiveFailedAttemptsLockAccount(): void
    {
        static::createClient(); // initialises the kernel + container

        $email = 'lockout-a@example.com';
        $this->createVerifiedUser($email, 'correct-horse-battery!');

        for ($i = 0; $i < 5; ++$i) {
            $this->submitLogin($email, 'wrongpassword');
        }

        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $em->clear(); // evict cached entity so we see the flushed state

        /** @var UserRepository $repo */
        $repo = $container->get(UserRepository::class);
        $user = $repo->findByEmail($email);

        self::assertNotNull($user);
        self::assertGreaterThanOrEqual(5, $user->getFailedLoginAttempts(), 'failedLoginAttempts must be ≥ 5 after five bad logins.');
        self::assertNotNull($user->getLockedUntil(), 'lockedUntil must be set after threshold is reached.');
        self::assertTrue($user->isLocked(), 'isLocked() must return true while lockedUntil is in the future.');
    }

    /**
     * (b) A locked account is rejected even when the CORRECT password is supplied.
     *
     * We directly set the lock on the entity (bypassing the HTTP flow) so this
     * test is independent of (a) and focuses purely on the CheckPassportEvent guard.
     */
    public function testLockedAccountRejectsCorrectPassword(): void
    {
        static::createClient(); // initialises the kernel + container

        $email = 'lockout-b@example.com';
        $plain = 'correct-horse-battery!';
        $this->createVerifiedUser($email, $plain);

        // Force the lock by manipulating the entity directly.
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserRepository $repo */
        $repo = $container->get(UserRepository::class);
        $user = $repo->findByEmail($email);
        self::assertNotNull($user);

        $user->setFailedLoginAttempts(5);
        $user->setLockedUntil(new \DateTimeImmutable('+15 minutes'));
        $em->flush();

        // Attempt login with the CORRECT password while the account is locked.
        $client = static::getClient();
        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => $plain,
        ]);
        $client->submit($form);

        // Must redirect back to /login (failure), never to home.
        self::assertResponseRedirects('/login', 302, 'Locked account must not be allowed through, even with correct password.');

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        // The lockout message must appear on the page.
        self::assertSelectorTextContains('.alert-error', 'temporarily locked');
    }

    /**
     * (c) A successful login resets the counters.
     *
     * We set counters to non-zero values, expire the lock, then log in
     * successfully and verify the entity was reset.
     */
    public function testSuccessfulLoginResetsCounters(): void
    {
        static::createClient(); // initialises the kernel + container

        $email = 'lockout-c@example.com';
        $plain = 'correct-horse-battery!';
        $this->createVerifiedUser($email, $plain);

        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserRepository $repo */
        $repo = $container->get(UserRepository::class);
        $user = $repo->findByEmail($email);
        self::assertNotNull($user);

        // Simulate a previous partial-lockout: counters elevated but cooldown has expired.
        $user->setFailedLoginAttempts(3);
        $user->setLockedUntil(new \DateTimeImmutable('-1 second')); // expired in the past → isLocked() = false
        $em->flush();

        // Log in successfully.
        $this->submitLogin($email, $plain);

        // Entity must be reset.
        $em->clear();
        $user = $repo->findByEmail($email);
        self::assertNotNull($user);
        self::assertSame(0, $user->getFailedLoginAttempts(), 'failedLoginAttempts must be reset to 0 after success.');
        self::assertNull($user->getLockedUntil(), 'lockedUntil must be null after a successful login.');
    }
}
