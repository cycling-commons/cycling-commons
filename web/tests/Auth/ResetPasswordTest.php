<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Auth;

use App\Entity\User;
use App\Repository\ResetPasswordRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Password reset flow tests:
 * 1. Request page renders
 * 2. Existing email → email sent + token row in DB
 * 3. Follow reset link → set new password → login with new password works
 * 4. Non-existent email → same check-email page (anti-enumeration), no email sent
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction so users and tokens created here never persist to subsequent tests or runs.
 */
final class ResetPasswordTest extends WebTestCase
{
    use MailerAssertionsTrait;

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Create and persist a verified User directly via the EntityManager,
     * bypassing the full register+verify email flow.
     */
    private function createVerifiedUser(string $email, string $displayName, string $plainPassword): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName($displayName);
        $user->setPassword($hasher->hashPassword($user, $plainPassword));
        $user->setRoles(['ROLE_USER']);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());

        $em->persist($user);
        $em->flush();

        return $user;
    }

    /**
     * Extract the reset-password path+token from the email body HTML.
     *
     * The reset URL is absolute (e.g. http://localhost/reset-password/reset/<token>);
     * we strip scheme+host so the test client can follow it as a relative path.
     */
    private function extractResetPath(string $htmlBody): string
    {
        preg_match('#href=["\']([^"\']*reset-password/reset/[^"\']+)["\']#', $htmlBody, $matches);
        self::assertNotEmpty($matches, 'Reset password URL not found in email body.');

        $url = html_entity_decode($matches[1]);
        $parsed = parse_url($url);

        $path = ($parsed['path'] ?? '/reset-password/reset/');
        $query = isset($parsed['query']) ? '?'.$parsed['query'] : '';

        return $path.$query;
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    public function testResetPasswordRequestPageRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/reset-password');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
        self::assertSelectorExists('input[name="reset_password_request_form[email]"]');
    }

    public function testRequestResetForExistingEmailSendsToken(): void
    {
        $client = static::createClient();

        $this->createVerifiedUser('reset-test@example.com', 'Reset Tester', 'initialpass12345!');

        $crawler = $client->request('GET', '/reset-password');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Send reset link')->form([
            'reset_password_request_form[email]' => 'reset-test@example.com',
        ]);
        $client->submit($form);

        // Exactly one email must have been dispatched
        self::assertEmailCount(1);
        $email = $this->getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'to', 'reset-test@example.com');

        $htmlBody = $email->getHtmlBody();
        self::assertIsString($htmlBody);
        self::assertStringContainsString('/reset-password/reset/', $htmlBody);

        // Should redirect to check-email
        self::assertResponseRedirects('/reset-password/check-email');
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        // Token row must exist in DB
        $repo = static::getContainer()->get(ResetPasswordRequestRepository::class);
        $requests = $repo->findAll();
        self::assertNotEmpty($requests, 'A reset password request row must exist in the DB.');
    }

    #[\Override]
    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    /**
     * The check-email page states the token lifetime, not the seconds left on
     * the wall clock. The token is minted on the POST and the page renders on
     * the GET that follows; the bundle clock is pinned five seconds behind so
     * that gap is deterministic instead of depending on a second boundary.
     */
    public function testCheckEmailPageStatesWholeHourLifetime(): void
    {
        $client = static::createClient();
        $this->createVerifiedUser('lifetime@example.com', 'Lifetime Tester', 'initialpass12345!');

        Clock::set(new MockClock((new \DateTimeImmutable())->modify('-5 seconds')));

        $crawler = $client->request('GET', '/reset-password');
        $form = $crawler->selectButton('Send reset link')->form([
            'reset_password_request_form[email]' => 'lifetime@example.com',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/reset-password/check-email');
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'The link expires in 1 hour');
    }

    public function testFollowResetLinkAndSetNewPassword(): void
    {
        $client = static::createClient();

        $this->createVerifiedUser('resetflow@example.com', 'Flow Tester', 'initialpass12345!');

        // Submit reset request
        $crawler = $client->request('GET', '/reset-password');
        $form = $crawler->selectButton('Send reset link')->form([
            'reset_password_request_form[email]' => 'resetflow@example.com',
        ]);
        $client->submit($form);

        // Extract reset URL from email before following redirect
        self::assertEmailCount(1);
        $email = $this->getMailerMessage();
        self::assertNotNull($email);
        $htmlBody = $email->getHtmlBody();
        self::assertIsString($htmlBody);
        $resetPath = $this->extractResetPath($htmlBody);

        self::assertResponseRedirects('/reset-password/check-email');
        $client->followRedirect();

        // Follow the reset link (GET /reset-password/reset/{token})
        // — this stores token in session and redirects to /reset-password/reset
        $client->request('GET', $resetPath);
        self::assertResponseRedirects('/reset-password/reset');
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        // Submit new password form
        $crawler = $client->getCrawler();
        $form = $crawler->selectButton('Set new password')->form([
            'change_password_form[plainPassword][first]' => 'newSecurePass9999!',
            'change_password_form[plainPassword][second]' => 'newSecurePass9999!',
        ]);
        $client->submit($form);

        // Should redirect to login with success flash
        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        // Now log in with the new password
        $crawler = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Sign in')->form([
            '_username' => 'resetflow@example.com',
            '_password' => 'newSecurePass9999!',
        ]);
        $client->submit($form);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/login', (string) $client->getRequest()->getUri());
    }

    /**
     * (#20) Completing a password reset must also clear any account lockout —
     * otherwise a locked-out owner who resets their password still cannot log
     * in, and the reset (their recovery path) is a dead end.
     */
    public function testPasswordResetClearsAccountLockout(): void
    {
        $client = static::createClient();

        $user = $this->createVerifiedUser('reset-lock@example.com', 'Locked Rider', 'initialpass12345!');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user->setFailedLoginAttempts(5);
        $user->setLockedUntil(new \DateTimeImmutable('+15 minutes'));
        $em->flush();

        // Request + follow the reset flow.
        $crawler = $client->request('GET', '/reset-password');
        $form = $crawler->selectButton('Send reset link')->form([
            'reset_password_request_form[email]' => 'reset-lock@example.com',
        ]);
        $client->submit($form);

        $email = $this->getMailerMessage();
        self::assertNotNull($email);
        $htmlBody = $email->getHtmlBody();
        self::assertIsString($htmlBody);
        $resetPath = $this->extractResetPath($htmlBody);

        $client->followRedirect(); // → check-email
        $client->request('GET', $resetPath);
        $client->followRedirect(); // → /reset-password/reset

        $crawler = $client->getCrawler();
        $form = $crawler->selectButton('Set new password')->form([
            'change_password_form[plainPassword][first]' => 'newSecurePass9999!',
            'change_password_form[plainPassword][second]' => 'newSecurePass9999!',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/login');

        // Lockout must be cleared.
        $em->clear();
        $repo = static::getContainer()->get(\App\Repository\UserRepository::class);
        $reloaded = $repo->findByEmail('reset-lock@example.com');
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getLockedUntil(), 'reset must clear the lockout');
        self::assertSame(0, $reloaded->getFailedLoginAttempts(), 'reset must reset the failed-attempt counter');
        self::assertFalse($reloaded->isLocked());
    }

    public function testRequestResetForNonExistentEmailShowsSameResponse(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/reset-password');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Send reset link')->form([
            'reset_password_request_form[email]' => 'nobody@example.com',
        ]);
        $client->submit($form);

        // Must still redirect to check-email (anti-enumeration — same response regardless of account existence)
        self::assertResponseRedirects('/reset-password/check-email');
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        // No email must have been sent
        self::assertEmailCount(0);
    }
}
