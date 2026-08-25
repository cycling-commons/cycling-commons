<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Controller\RegistrationController;
use App\Controller\ResetPasswordController;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Per-address budgets on the two anonymous endpoints that send mail
 * (account-and-auth.md §2).
 *
 * Both shipped with no quota at all until the 2026-08-25 security scan. One
 * unauthenticated POST to either persists a row and sends a message to an
 * address the sender chose, so a loop turns this site into a mail cannon
 * pointed at somebody else's inbox and floods our own tables on the way.
 *
 * ## Why these tests are shaped so oddly
 *
 * Under `when@test` the limiter pools are array adapters, and Symfony resets
 * every resettable service at the START of a `handle()` that follows a
 * `terminate()`. So a counter spent outside a request survives into the NEXT
 * request and no further: five real submissions in a loop each start from an
 * empty counter and the wall is never reached, and a budget drained after a
 * warm-up GET is wiped before the POST that was supposed to hit it.
 *
 * That leaves exactly one working shape: drain the limiter, then make the
 * POST the very first request of the test. MediaReportEndpointTest reaches the
 * same conclusion. The cost is that there is no earlier GET to take a CSRF
 * token from, so the double-submit pair is planted directly instead: forms use
 * the stateless token id `submit` (csrf.yaml), and SameOriginCsrfTokenManager
 * accepts any value of at least 24 characters that arrives both in the payload
 * and as a `csrf-token_<value>` cookie. Each test carries its positive control
 * beside it, so a token that silently stopped validating would show up as the
 * "within budget" case failing rather than as a false pass.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction.
 */
final class AnonymousMailFloodTest extends WebTestCase
{
    /** Any value of >= 24 chars works; it only has to match the cookie. */
    private const string CSRF = 'ccTestCsrfTokenValue0123456789';

    private const string PASSWORD = 'hunter2secure!';

    private function rider(string $email): void
    {
        $container = static::getContainer();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Flood Target');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));

        $em->persist($user);
        $em->flush();
    }

    /** Plant the double-submit cookie that pairs with self::CSRF in the payload. */
    private function armCsrf(KernelBrowser $client): void
    {
        $client->getCookieJar()->set(new Cookie('csrf-token_'.self::CSRF, 'csrf-token', null, '/', 'localhost'));
    }

    private function mailsSent(): int
    {
        return \count(static::getContainer()->get('mailer.message_logger_listener')->getEvents()->getEvents());
    }

    private function secret(): string
    {
        return (string) static::getContainer()->getParameter('kernel.secret');
    }

    private function drain(string $limiterService, string $key): void
    {
        $limiter = static::getContainer()->get($limiterService);
        for ($i = 1; $i <= 5; ++$i) {
            self::assertTrue($limiter->create($key)->consume()->isAccepted(), "request {$i} is within the hour's allowance");
        }
    }

    // ── password reset ───────────────────────────────────────────────────────

    public function testTheSixthPasswordResetRequestSendsNoMail(): void
    {
        $client = static::createClient();
        $this->rider('flood-reset@example.com');
        $this->drain('limiter.password_reset', ResetPasswordController::anonKey('127.0.0.1', $this->secret()));

        $this->armCsrf($client);
        $client->request('POST', '/reset-password', ['reset_password_request_form' => [
            'email' => 'flood-reset@example.com',
            '_token' => self::CSRF,
        ]]);

        // Same answer a real request gets: an anonymous caller must not learn
        // from a 429 which addresses have accounts.
        self::assertResponseRedirects('/reset-password/check-email');
        self::assertSame(0, $this->mailsSent(), 'the sixth request sent nothing');
    }

    /** Positive control: the identical request under budget does send its mail. */
    public function testAPasswordResetRequestWithinBudgetStillSendsMail(): void
    {
        $client = static::createClient();
        $this->rider('flood-reset-ok@example.com');

        $this->armCsrf($client);
        $client->request('POST', '/reset-password', ['reset_password_request_form' => [
            'email' => 'flood-reset-ok@example.com',
            '_token' => self::CSRF,
        ]]);

        self::assertResponseRedirects('/reset-password/check-email');
        self::assertSame(1, $this->mailsSent());
    }

    // ── registration ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function registrationPayload(string $email): array
    {
        return ['registration_form' => [
            'email' => $email,
            'displayName' => 'Flood Rider',
            'plainPassword' => ['first' => self::PASSWORD, 'second' => self::PASSWORD],
            'confirmAge' => '1',
            'agreeTerms' => '1',
            '_token' => self::CSRF,
        ]];
    }

    private function userCount(): int
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM users');
    }

    public function testTheSixthRegistrationIsRefusedAndWritesNothing(): void
    {
        $client = static::createClient();
        $before = $this->userCount();
        $this->drain('limiter.registration', RegistrationController::anonKey('127.0.0.1', $this->secret()));

        $this->armCsrf($client);
        $client->request('POST', '/register', $this->registrationPayload('flood-reg@example.com'));

        self::assertResponseStatusCodeSame(429, 'the sixth sign-up is refused');
        self::assertSame(0, $this->mailsSent(), 'and sends no confirmation mail');
        self::assertSame($before, $this->userCount(), 'a refused sign-up leaves no user row behind');
    }

    /** Positive control: the identical form under budget creates the account. */
    public function testARegistrationWithinBudgetStillSucceeds(): void
    {
        $client = static::createClient();
        $before = $this->userCount();

        $this->armCsrf($client);
        $client->request('POST', '/register', $this->registrationPayload('flood-reg-ok@example.com'));

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->mailsSent());
        self::assertSame($before + 1, $this->userCount());
    }
}
