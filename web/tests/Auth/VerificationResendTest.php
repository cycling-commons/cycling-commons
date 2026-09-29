<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Controller\VerificationResendController;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\FormGuard;
use App\Security\ProofOfWork;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Mime\Email;

/**
 * A new confirmation link for an account that never confirmed
 * (account-and-auth.md §2).
 *
 * Every answer is the same page, so the form tells nobody which addresses have
 * accounts. The limiter cases use AnonymousMailFloodTest's shape: drain the
 * limiter, then make the POST the first request, because the test's array
 * pools are reset at the start of every later request.
 */
final class VerificationResendTest extends WebTestCase
{
    use GuardedSignupTrait;
    use MailerAssertionsTrait;

    /** Any value of >= 24 chars works; it only has to match the cookie. */
    private const string CSRF = 'ccTestCsrfTokenValue0123456789';

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function rider(string $email, bool $verified = false): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Waiting Rider');
        $user->setEmailVerified($verified);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword('x');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /** @param array<string, string> $overrides */
    private function ask(KernelBrowser $client, string $email, array $overrides = []): void
    {
        $page = $client->request('GET', '/verify/resend');
        self::assertResponseIsSuccessful();
        $client->request('POST', '/verify/resend', $this->guarded($page, $overrides + ['verification_resend_form[email]' => $email]));
    }

    /** The POST as the first request, with the double-submit CSRF pair planted. */
    private function askFirst(KernelBrowser $client, string $email): void
    {
        $client->getCookieJar()->set(new Cookie('csrf-token_'.self::CSRF, 'csrf-token', null, '/', 'localhost'));
        $client->request('POST', '/verify/resend', [
            'verification_resend_form' => ['email' => $email, '_token' => self::CSRF],
            'pow_challenge' => $challenge = $this->powChallenge(),
            'pow_nonce' => $this->solvePow($challenge, ProofOfWork::DIFFICULTY),
            FormGuard::HONEYPOT_A => '',
            FormGuard::HONEYPOT_B => '',
            FormGuard::STAMP => $this->agedStamp(),
        ]);
    }

    private function drain(string $service, string $key, int $times): void
    {
        $limiter = static::getContainer()->get($service);
        for ($i = 1; $i <= $times; ++$i) {
            self::assertTrue($limiter->create($key)->consume()->isAccepted());
        }
    }

    private function secret(): string
    {
        return (string) static::getContainer()->getParameter('kernel.secret');
    }

    private function mailsSent(): int
    {
        return \count(static::getContainer()->get('mailer.message_logger_listener')->getEvents()->getEvents());
    }

    public function testThePageRenders(): void
    {
        $client = $this->client();
        $client->request('GET', '/verify/resend');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form#cc-guarded-form input[name="verification_resend_form[email]"]');
    }

    public function testAnUnconfirmedAccountGetsANewLinkThatWorks(): void
    {
        $client = $this->client();
        $this->rider('waiting@example.com');

        $this->ask($client, 'Waiting@Example.com');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.resend-sent');
        self::assertEmailCount(1);
        $email = $this->getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'to', 'waiting@example.com');

        preg_match('/href="([^"]*\/verify\/email[^"]*)"/', (string) $email->getHtmlBody(), $m);
        $parts = parse_url(html_entity_decode($m[1]));
        $client->request('GET', $parts['path'].'?'.$parts['query']);
        self::assertResponseRedirects('/login');

        $user = static::getContainer()->get(UserRepository::class)->findByEmail('waiting@example.com');
        self::assertNotNull($user);
        self::assertTrue($user->isEmailVerified());
    }

    public function testAConfirmedAccountGetsTheSameAnswerAndNoMail(): void
    {
        $client = $this->client();
        $this->rider('done@example.com', verified: true);

        $this->ask($client, 'done@example.com');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.resend-sent');
        self::assertEmailCount(0);
    }

    public function testAnUnknownAddressGetsTheSameAnswerAndNoMail(): void
    {
        $client = $this->client();

        $this->ask($client, 'nobody@example.com');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.resend-sent');
        self::assertEmailCount(0);
    }

    public function testAFilledHoneypotSendsNothing(): void
    {
        $client = $this->client();
        $this->rider('trap@example.com');

        $this->ask($client, 'trap@example.com', [FormGuard::HONEYPOT_A => 'x']);

        self::assertResponseStatusCodeSame(422);
        self::assertEmailCount(0);
    }

    public function testOneLinkPerQuarterHourPerAddress(): void
    {
        $client = $this->client();
        $this->rider('quarter@example.com');
        $this->drain('limiter.verify_resend_address', VerificationResendController::addressKey('quarter@example.com', $this->secret()), 1);

        $this->askFirst($client, 'quarter@example.com');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.resend-sent', 'the same answer, so the limit reveals nothing');
        self::assertSame(0, $this->mailsSent());
    }

    public function testThreeLinksPerDayPerAddress(): void
    {
        $client = $this->client();
        $this->rider('daily@example.com');
        $this->drain('limiter.verify_resend_address_daily', VerificationResendController::addressKey('daily@example.com', $this->secret()), 3);

        $this->askFirst($client, 'daily@example.com');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->mailsSent());
    }

    public function testFivePerHourPerConnection(): void
    {
        $client = $this->client();
        $this->rider('busy@example.com');
        $this->drain('limiter.verify_resend', VerificationResendController::ipKey('127.0.0.1', $this->secret()), 5);

        $this->askFirst($client, 'busy@example.com');

        self::assertResponseStatusCodeSame(429);
        self::assertSame(0, $this->mailsSent());
    }

    /** Positive control for the three limiter cases: the same first-request POST does send. */
    public function testAFirstRequestWithinBudgetSends(): void
    {
        $client = $this->client();
        $this->rider('control@example.com');

        $this->askFirst($client, 'control@example.com');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->mailsSent());
    }

    public function testABrokenOrExpiredLinkLeadsHere(): void
    {
        $client = $this->client();
        $user = $this->rider('expired@example.com');

        $client->request('GET', '/verify/email?id='.$user->getId().'&expires=1&signature=x&token=y');

        self::assertResponseRedirects('/verify/resend');
        $client->followRedirect();
        self::assertSelectorExists('form#cc-guarded-form');
        self::assertSelectorExists('.alert-error');
    }
}
