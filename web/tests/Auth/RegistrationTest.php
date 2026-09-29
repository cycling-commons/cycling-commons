<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\FormGuard;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Registration flow: POST /register → unverified user + verification email;
 * follow signed verify URL → emailVerified=true; then login succeeds.
 *
 * Every POST goes through {@see GuardedSignupTrait}, so a validation case
 * cannot pass only because a bot guard refused the request first.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction so users created here never persist to subsequent tests or runs.
 */
final class RegistrationTest extends WebTestCase
{
    use GuardedSignupTrait;
    use MailerAssertionsTrait;

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function findUser(string $email): ?User
    {
        return static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
    }

    /**
     * The signed verify-email path+query from the email body, relative so the
     * test client can follow it.
     */
    private function extractVerifyPath(string $htmlBody): string
    {
        preg_match('/href=["\']([^"\']*\/verify\/email[^"\']*)["\']/', $htmlBody, $matches);
        self::assertNotEmpty($matches, 'Signed verify URL not found in email body.');

        $parsed = parse_url(html_entity_decode($matches[1]));

        return ($parsed['path'] ?? '/verify/email').(isset($parsed['query']) ? '?'.$parsed['query'] : '');
    }

    private function onlyMail(): Email
    {
        self::assertEmailCount(1);
        $email = $this->getMailerMessage();
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    public function testRegisterPageRenders(): void
    {
        $client = $this->client();
        $client->request('GET', '/register');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form#cc-guarded-form');
        self::assertSelectorExists('input[name="registration_form[email]"]');
        self::assertSelectorExists('input[name="registration_form[displayName]"]');
        self::assertSelectorExists('input[name="registration_form[plainPassword][first]"]');
        self::assertSelectorExists('input[name="registration_form[plainPassword][second]"]');
        self::assertSelectorExists('input[name="'.FormGuard::HONEYPOT_A.'"]');
        self::assertSelectorExists('input[name="'.FormGuard::STAMP.'"]');
    }

    public function testRegistrationCreatesUnverifiedUserAndSendsEmail(): void
    {
        $client = $this->client();
        $this->signUp($client, 'newrider@example.com');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('p.auth-heading', 'Verify your email');

        $user = $this->findUser('newrider@example.com');
        self::assertNotNull($user, 'User should exist in the database after registration.');
        self::assertFalse($user->isEmailVerified(), 'Newly registered user must NOT be email-verified yet.');
        self::assertContains('ROLE_USER', $user->getRoles(), 'User must have ROLE_USER.');

        $email = $this->onlyMail();
        self::assertEmailAddressContains($email, 'to', 'newrider@example.com');
        self::assertStringContainsString('/verify/email', (string) $email->getHtmlBody());
    }

    /**
     * Bots sign up strangers' addresses with a name of their choosing. Put in
     * the mail, that name is text a stranger chose, sent from our domain.
     */
    public function testTheConfirmationMailCarriesNoDisplayName(): void
    {
        $client = $this->client();
        $this->signUp($client, 'stranger@example.com', 'TQzXxAvQrqrRKJjPh');

        $email = $this->onlyMail();
        self::assertSame('', $email->getTo()[0]->getName());
        self::assertStringNotContainsString('TQzXxAvQrqrRKJjPh', (string) $email->getHtmlBody());
        self::assertStringNotContainsString('TQzXxAvQrqrRKJjPh', (string) $email->getTextBody());
    }

    public function testTheVerifyLinkLastsADay(): void
    {
        $client = $this->client();
        $before = time();
        $this->signUp($client, 'slowreader@example.com');

        $path = $this->extractVerifyPath((string) $this->onlyMail()->getHtmlBody());
        parse_str((string) parse_url($path, \PHP_URL_QUERY), $query);
        $lifetime = (int) $query['expires'] - $before;

        self::assertGreaterThanOrEqual(86400, $lifetime);
        self::assertLessThan(86400 + 60, $lifetime);
    }

    public function testTheEmailIsStoredInLowerCase(): void
    {
        $client = $this->client();
        $this->signUp($client, 'MixedCase@Example.com');

        self::assertResponseIsSuccessful();
        self::assertNotNull($this->findUser('mixedcase@example.com'));
    }

    public function testTheSameAddressInOtherCaseIsTaken(): void
    {
        $client = $this->client();
        $this->signUp($client, 'twice@example.com');
        self::assertResponseIsSuccessful();

        $this->signUp($client, 'Twice@EXAMPLE.com', 'Second Rider');

        self::assertResponseStatusCodeSame(422);
        self::assertEmailCount(0, message: 'the second attempt must not mail the address again');
    }

    public function testAFilledHoneypotCreatesNothing(): void
    {
        $client = $this->client();
        $this->signUp($client, 'honeypot@example.com', overrides: [FormGuard::HONEYPOT_B => 'https://spam.example']);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->findUser('honeypot@example.com'));
        self::assertEmailCount(0);
    }

    public function testAFormSentWithinSecondsOfLoadingCreatesNothing(): void
    {
        $client = $this->client();
        // Dated ahead, not "one second ago": solving the proof of work below
        // takes seconds, which would age a fresh stamp past the minimum.
        $fresh = static::getContainer()->get(FormGuard::class)->stamp(new \DateTimeImmutable('+60 seconds'));
        $this->signUp($client, 'toofast@example.com', overrides: [FormGuard::STAMP => $fresh]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->findUser('toofast@example.com'));
        self::assertEmailCount(0);
    }

    public function testAnUnsolvedProofOfWorkCreatesNothing(): void
    {
        $client = $this->client();
        $this->signUp($client, 'nopow@example.com', overrides: ['pow_nonce' => '0']);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->findUser('nopow@example.com'));
        self::assertEmailCount(0);
    }

    public function testADomainWithNoMailServerCreatesNothing(): void
    {
        $client = $this->client();
        $this->signUp($client, 'rider@no-such-domain.invalid');

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->findUser('rider@no-such-domain.invalid'));
        self::assertEmailCount(0);
    }

    public function testFollowingVerifyLinkMarksEmailVerified(): void
    {
        $client = $this->client();
        $this->signUp($client, 'verifytest@example.com');
        self::assertResponseIsSuccessful();

        $client->request('GET', $this->extractVerifyPath((string) $this->onlyMail()->getHtmlBody()));

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $user = $this->findUser('verifytest@example.com');
        self::assertNotNull($user);
        self::assertTrue($user->isEmailVerified(), 'User must be email-verified after following the verify link.');
        self::assertNotNull($user->getEmailVerifiedAt(), 'emailVerifiedAt must be set after verification.');
    }

    public function testVerifiedUserCanLogin(): void
    {
        $client = $this->client();
        $this->signUp($client, 'logintest@example.com');
        self::assertResponseIsSuccessful();

        $client->request('GET', $this->extractVerifyPath((string) $this->onlyMail()->getHtmlBody()));
        self::assertResponseRedirects('/login');

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Sign in')->form([
            '_username' => 'logintest@example.com',
            '_password' => 'securepass12345!',
        ]));

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/login', (string) $client->getRequest()->getUri());
    }

    /**
     * The age gate (GDPR Art. 8, owner decision 2026-08-01: flat 16 for
     * everyone). Self-declared and required — an unticked box creates nothing,
     * and a ticked one is recorded so the declaration can be evidenced later
     * (Art. 5(2)) without ever storing a date of birth (Art. 5(1)(c)).
     */
    public function testRegistrationWithoutTheAgeDeclarationCreatesNoAccount(): void
    {
        $client = $this->client();
        $page = $client->request('GET', '/register');
        $client->request('POST', '/register', $this->guarded($page, [
            'registration_form[email]' => 'tooyoung@example.com',
            'registration_form[displayName]' => 'Young Rider',
            'registration_form[plainPassword][first]' => 'securepass12345!',
            'registration_form[plainPassword][second]' => 'securepass12345!',
            'registration_form[agreeTerms]' => '1',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', '16 or older');
        self::assertNull($this->findUser('tooyoung@example.com'), 'an account is never created without the declaration');
    }

    public function testTheAgeDeclarationIsRecordedWithoutADateOfBirth(): void
    {
        $client = $this->client();
        $this->signUp($client, 'oldenough@example.com');

        $user = $this->findUser('oldenough@example.com');
        self::assertNotNull($user);
        self::assertNotNull($user->getAgeConfirmedAt(), 'the declaration is evidenced');
        self::assertFalse(
            method_exists($user, 'getDateOfBirth'),
            'a yes/no question must not put a birthday on file',
        );
    }

    public function testDuplicateEmailShowsError(): void
    {
        $client = $this->client();
        $this->signUp($client, 'duplicate@example.com', 'First Rider');
        self::assertResponseIsSuccessful();

        $this->signUp($client, 'duplicate@example.com', 'Second Rider');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('input[name="registration_form[email]"]');
    }

    public function testMalformedEmailIsRejectedWithoutCreatingUser(): void
    {
        $client = $this->client();
        $this->signUp($client, 'not-an-email');

        // Server-side Email constraint rejects it (no 500 from RfcCompliance at
        // Address(), no committed orphan row).
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->findUser('not-an-email'));
    }

    /**
     * The Email constraint's default mode accepts `j..t@gmail.com`; the mailer
     * does not. It used to throw at `new Address()`: a 500, with the row
     * already written, so the address was then "taken" (GlitchTip, 2026-09-29).
     */
    public function testAnAddressTheMailerRefusesIsAFormErrorNotAServerError(): void
    {
        $client = $this->client();
        $this->signUp($client, 'j..t.omr.i.c.h@gmail.com');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form#cc-guarded-form', 'two dots in a row');
        self::assertNull($this->findUser('j..t.omr.i.c.h@gmail.com'));
        self::assertEmailCount(0);
    }

    public function testOverlongEmailIsRejectedWithoutDatabaseError(): void
    {
        $client = $this->client();
        $this->signUp($client, str_repeat('a', 180).'@example.com'); // > column length 180

        // Length constraint stops it at validation, not at flush (no DBAL 500).
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('input[name="registration_form[email]"]');
    }

    public function testInvisibleCharacterInDisplayNameIsRejected(): void
    {
        $client = $this->client();
        $this->signUp($client, 'zwsp@example.com', "Bad\u{200B}Name"); // zero-width space

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->findUser('zwsp@example.com'));
    }

    public function testPasswordTooShortShowsError(): void
    {
        $client = $this->client();
        $this->signUp($client, 'short@example.com', overrides: [
            'registration_form[plainPassword][first]' => 'short',
            'registration_form[plainPassword][second]' => 'short',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->findUser('short@example.com'));
    }
}
