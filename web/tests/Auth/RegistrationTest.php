<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Tests\Auth;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Registration flow: POST /register → unverified user + verification email;
 * follow signed verify URL → emailVerified=true; then login succeeds.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction so users created here never persist to subsequent tests or runs.
 */
final class RegistrationTest extends WebTestCase
{
    use MailerAssertionsTrait;

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Extract the signed verify-email path+query from the email body HTML.
     *
     * The signed URL is absolute (e.g. http://localhost/verify/email?...);
     * we strip the scheme+host so the test client can follow it as a relative path.
     */
    private function extractVerifyPath(string $htmlBody): string
    {
        preg_match('/href=["\']([^"\']*\/verify\/email[^"\']*)["\']/', $htmlBody, $matches);
        self::assertNotEmpty($matches, 'Signed verify URL not found in email body.');

        $url = html_entity_decode($matches[1]);
        $parsed = parse_url($url);

        $path = ($parsed['path'] ?? '/verify/email');
        $query = isset($parsed['query']) ? '?'.$parsed['query'] : '';

        return $path.$query;
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    public function testRegisterPageRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/register');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
        self::assertSelectorExists('input[name="registration_form[email]"]');
        self::assertSelectorExists('input[name="registration_form[displayName]"]');
        self::assertSelectorExists('input[name="registration_form[plainPassword][first]"]');
        self::assertSelectorExists('input[name="registration_form[plainPassword][second]"]');
    }

    public function testRegistrationCreatesUnverifiedUserAndSendsEmail(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/register');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Create account')->form([
            'registration_form[email]' => 'newrider@example.com',
            'registration_form[displayName]' => 'New Rider',
            'registration_form[plainPassword][first]' => 'securepass12345!',
            'registration_form[plainPassword][second]' => 'securepass12345!',
            'registration_form[agreeTerms]' => true,
        ]);
        $client->submit($form);

        // Should show check-email page
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('p.auth-heading', 'Verify your email');

        // User must exist in DB and be unverified
        $container = static::getContainer();
        /** @var UserRepository $userRepo */
        $userRepo = $container->get(UserRepository::class);
        $user = $userRepo->findOneBy(['email' => 'newrider@example.com']);

        self::assertNotNull($user, 'User should exist in the database after registration.');
        self::assertFalse($user->isEmailVerified(), 'Newly registered user must NOT be email-verified yet.');
        self::assertContains('ROLE_USER', $user->getRoles(), 'User must have ROLE_USER.');

        // Exactly one email must have been dispatched
        self::assertEmailCount(1);

        $email = $this->getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'to', 'newrider@example.com');

        $htmlBody = $email->getHtmlBody();
        self::assertIsString($htmlBody);
        self::assertStringContainsString('/verify/email', $htmlBody);
    }

    public function testFollowingVerifyLinkMarksEmailVerified(): void
    {
        $client = static::createClient();

        // Register
        $crawler = $client->request('GET', '/register');
        $form = $crawler->selectButton('Create account')->form([
            'registration_form[email]' => 'verifytest@example.com',
            'registration_form[displayName]' => 'Verify Rider',
            'registration_form[plainPassword][first]' => 'securepass12345!',
            'registration_form[plainPassword][second]' => 'securepass12345!',
            'registration_form[agreeTerms]' => true,
        ]);
        $client->submit($form);
        self::assertResponseIsSuccessful();

        // Extract signed URL from email body
        $email = $this->getMailerMessage();
        self::assertNotNull($email);

        $htmlBody = $email->getHtmlBody();
        self::assertIsString($htmlBody);

        $verifyPath = $this->extractVerifyPath($htmlBody);

        // Follow the signed URL
        $client->request('GET', $verifyPath);

        // Should redirect to login with a success flash
        self::assertResponseRedirects('/login');
        $client->followRedirect();

        self::assertResponseIsSuccessful();

        // Verify user is now marked as verified in DB
        $container = static::getContainer();
        /** @var UserRepository $userRepo */
        $userRepo = $container->get(UserRepository::class);
        $user = $userRepo->findOneBy(['email' => 'verifytest@example.com']);

        self::assertNotNull($user);
        self::assertTrue($user->isEmailVerified(), 'User must be email-verified after following the verify link.');
        self::assertNotNull($user->getEmailVerifiedAt(), 'emailVerifiedAt must be set after verification.');
    }

    public function testVerifiedUserCanLogin(): void
    {
        $client = static::createClient();

        // Register
        $crawler = $client->request('GET', '/register');
        $form = $crawler->selectButton('Create account')->form([
            'registration_form[email]' => 'logintest@example.com',
            'registration_form[displayName]' => 'Login Rider',
            'registration_form[plainPassword][first]' => 'securepass12345!',
            'registration_form[plainPassword][second]' => 'securepass12345!',
            'registration_form[agreeTerms]' => true,
        ]);
        $client->submit($form);
        self::assertResponseIsSuccessful();

        // Get signed URL from email and follow it
        $email = $this->getMailerMessage();
        self::assertNotNull($email);

        $htmlBody = $email->getHtmlBody();
        self::assertIsString($htmlBody);

        $verifyPath = $this->extractVerifyPath($htmlBody);
        $client->request('GET', $verifyPath);
        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        // Now log in with the same credentials
        $crawler = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Sign in')->form([
            '_username' => 'logintest@example.com',
            '_password' => 'securepass12345!',
        ]);
        $client->submit($form);

        self::assertResponseRedirects();
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/login', (string) $client->getRequest()->getUri());
    }

    public function testDuplicateEmailShowsError(): void
    {
        $client = static::createClient();

        // Register first user
        $crawler = $client->request('GET', '/register');
        $form = $crawler->selectButton('Create account')->form([
            'registration_form[email]' => 'duplicate@example.com',
            'registration_form[displayName]' => 'First Rider',
            'registration_form[plainPassword][first]' => 'securepass12345!',
            'registration_form[plainPassword][second]' => 'securepass12345!',
            'registration_form[agreeTerms]' => true,
        ]);
        $client->submit($form);
        self::assertResponseIsSuccessful(); // check-email page

        // Register second user with same email (same client, same kernel)
        $crawler = $client->request('GET', '/register');
        $form = $crawler->selectButton('Create account')->form([
            'registration_form[email]' => 'duplicate@example.com',
            'registration_form[displayName]' => 'Second Rider',
            'registration_form[plainPassword][first]' => 'securepass12345!',
            'registration_form[plainPassword][second]' => 'securepass12345!',
            'registration_form[agreeTerms]' => true,
        ]);
        $client->submit($form);

        // Should stay on the register page with a validation error (422 Unprocessable Content)
        self::assertResponseStatusCodeSame(422);
        // The page should still be the registration form (not check_email)
        self::assertSelectorExists('input[name="registration_form[email]"]');
    }

    public function testMalformedEmailIsRejectedWithoutCreatingUser(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Create account')->form([
            'registration_form[email]' => 'not-an-email',
            'registration_form[displayName]' => 'Bad Email',
            'registration_form[plainPassword][first]' => 'securepass12345!',
            'registration_form[plainPassword][second]' => 'securepass12345!',
            'registration_form[agreeTerms]' => true,
        ]);
        $client->submit($form);

        // Server-side Email constraint rejects it (no 500 from RfcCompliance at
        // Address(), no committed orphan row).
        self::assertResponseStatusCodeSame(422);
        /** @var UserRepository $userRepo */
        $userRepo = static::getContainer()->get(UserRepository::class);
        self::assertNull($userRepo->findOneBy(['email' => 'not-an-email']));
    }

    public function testOverlongEmailIsRejectedWithoutDatabaseError(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');
        self::assertResponseIsSuccessful();

        $long = str_repeat('a', 180).'@example.com'; // > column length 180

        $form = $crawler->selectButton('Create account')->form([
            'registration_form[email]' => $long,
            'registration_form[displayName]' => 'Long Email',
            'registration_form[plainPassword][first]' => 'securepass12345!',
            'registration_form[plainPassword][second]' => 'securepass12345!',
            'registration_form[agreeTerms]' => true,
        ]);
        $client->submit($form);

        // Length constraint stops it at validation, not at flush (no DBAL 500).
        self::assertResponseStatusCodeSame(422);
    }

    public function testInvisibleCharacterInDisplayNameIsRejected(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Create account')->form([
            'registration_form[email]' => 'zwsp@example.com',
            'registration_form[displayName]' => "Bad\u{200B}Name", // zero-width space
            'registration_form[plainPassword][first]' => 'securepass12345!',
            'registration_form[plainPassword][second]' => 'securepass12345!',
            'registration_form[agreeTerms]' => true,
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        /** @var UserRepository $userRepo */
        $userRepo = static::getContainer()->get(UserRepository::class);
        self::assertNull($userRepo->findOneBy(['email' => 'zwsp@example.com']));
    }

    public function testPasswordTooShortShowsError(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Create account')->form([
            'registration_form[email]' => 'short@example.com',
            'registration_form[displayName]' => 'Short Pass',
            'registration_form[plainPassword][first]' => 'short',
            'registration_form[plainPassword][second]' => 'short',
            'registration_form[agreeTerms]' => true,
        ]);
        $client->submit($form);

        // Symfony returns 422 for form validation errors
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('input[name="registration_form[email]"]'); // form still rendered
    }
}
