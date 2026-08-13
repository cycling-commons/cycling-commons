<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Tests\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UserDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Account deletion flow: code request, confirmation, hook invocation, expiry rejection.
 *
 * Uses DAMA isolation (each test rolls back). Tests operate via:
 * - Direct service calls (requestDeletion/confirmDeletion) for unit-style coverage.
 * - HTTP browser for black-box route coverage.
 *
 * Mailer: null://null (emails silently discarded in test env — we verify side-effects on
 * the entity rather than inspecting sent mail).
 */
final class AccountDeletionTest extends WebTestCase
{
    // ── Helpers ─────────────────────────────────────────────────────────────

    private function createUser(
        string $email = 'delete-me@example.com',
        string $plain = 'securepass12345!',
        string $displayName = 'Delete Me',
    ): User {
        $container = static::getContainer();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName($displayName);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPassword($hasher->hashPassword($user, $plain));

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function loginAs(KernelBrowser $client, string $email, string $plain): void
    {
        $crawler = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => $plain,
        ]);
        $client->submit($form);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    private function fetchUser(string $email): ?User
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        /** @var UserRepository $repo */
        $repo = static::getContainer()->get(UserRepository::class);

        return $repo->findOneBy(['email' => $email]);
    }

    // ── (a) requestDeletion sets code + timestamp ────────────────────────────

    public function testRequestDeletionSetsCodeAndTimestamp(): void
    {
        SpyDeletionHook::$callCount = 0;

        $user = $this->createUser('req-code@example.com');

        /** @var UserDeletionService $service */
        $service = static::getContainer()->get(UserDeletionService::class);
        $service->requestDeletion($user);

        // Re-fetch from DB (DAMA: still within rolled-back transaction, EM state is fresh)
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->refresh($user);

        self::assertNotNull($user->getDeletionCode(), 'deletionCode must be set after requestDeletion');
        self::assertMatchesRegularExpression('/^[A-F0-9]{8}$/', (string) $user->getDeletionCode(), 'Code must be 8 uppercase hex chars');
        self::assertNotNull($user->getDeletionRequestedAt(), 'deletionRequestedAt must be set');
        // No hook calls during request phase
        self::assertSame(0, SpyDeletionHook::$callCount);
    }

    // ── (b) confirmDeletion with correct code deletes user + calls hook ───────

    public function testConfirmDeletionWithCorrectCodeDeletesUserAndCallsHook(): void
    {
        SpyDeletionHook::$callCount = 0;

        $email = 'confirm-delete@example.com';
        $user = $this->createUser($email);

        /** @var UserDeletionService $service */
        $service = static::getContainer()->get(UserDeletionService::class);
        $service->requestDeletion($user);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->refresh($user);

        $code = $user->getDeletionCode();
        self::assertNotNull($code);

        $result = $service->confirmDeletion($user, $code);

        self::assertTrue($result, 'confirmDeletion must return true for correct code');
        self::assertSame(1, SpyDeletionHook::$callCount, 'Hook preDelete must be called once before deletion');

        // User must no longer exist in DB
        $found = $this->fetchUser($email);
        self::assertNull($found, 'User must be removed from DB after confirmed deletion');
    }

    /**
     * Regression (account-and-auth.md §6.3 known gap, fixed): deleting a user
     * with a LIVE password-reset request used to throw an FK violation —
     * reset_password_request.user_id is a restrictive FK (Version20260628225933)
     * and nothing purged the rows first. The ResetPasswordCleanupHook now
     * removes them inside purge(), on both deletion paths.
     */
    public function testConfirmDeletionSucceedsWithPendingResetRequest(): void
    {
        $email = 'delete-with-reset@example.com';
        $user = $this->createUser($email);

        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        // Seed a live reset request the way the bundle would.
        /** @var \App\Repository\ResetPasswordRequestRepository $resetRepo */
        $resetRepo = $container->get(\App\Repository\ResetPasswordRequestRepository::class);
        $request = $resetRepo->createResetPasswordRequest(
            $user,
            new \DateTimeImmutable('+1 hour'),
            'sel-'.bin2hex(random_bytes(8)), // selector column is varchar(20)
            'hashed-token-value',
        );
        $em->persist($request);
        $em->flush();

        /** @var UserDeletionService $service */
        $service = $container->get(UserDeletionService::class);
        $service->requestDeletion($user);
        $em->refresh($user);
        $code = $user->getDeletionCode();
        self::assertNotNull($code);

        $result = $service->confirmDeletion($user, $code);

        self::assertTrue($result, 'Deletion must succeed despite a pending reset request');
        self::assertNull($this->fetchUser($email), 'User must be removed from DB');
        self::assertSame(
            0,
            (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM reset_password_request'),
            'The pending reset request must be purged with the account',
        );
    }

    // ── (c) confirmDeletion with wrong code rejects ──────────────────────────

    public function testConfirmDeletionWithWrongCodeReturnsFalse(): void
    {
        SpyDeletionHook::$callCount = 0;

        $email = 'wrong-code@example.com';
        $user = $this->createUser($email);

        /** @var UserDeletionService $service */
        $service = static::getContainer()->get(UserDeletionService::class);
        $service->requestDeletion($user);

        $result = $service->confirmDeletion($user, 'WRONGCOD');

        self::assertFalse($result, 'confirmDeletion must return false for wrong code');
        self::assertSame(0, SpyDeletionHook::$callCount, 'Hook must NOT be called when code is wrong');

        $found = $this->fetchUser($email);
        self::assertNotNull($found, 'User must still exist after rejected deletion');
    }

    // ── (d) confirmDeletion with expired code rejects ────────────────────────

    public function testConfirmDeletionWithExpiredCodeReturnsFalse(): void
    {
        SpyDeletionHook::$callCount = 0;

        $email = 'expired-code@example.com';
        $user = $this->createUser($email);

        /** @var UserDeletionService $service */
        $service = static::getContainer()->get(UserDeletionService::class);
        $service->requestDeletion($user);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->refresh($user);

        // Manually expire the code by backdating requestedAt by 2 hours
        $user->setDeletionRequestedAt(new \DateTimeImmutable('-2 hours'));
        $em->flush();

        $code = $user->getDeletionCode();
        self::assertNotNull($code);

        $result = $service->confirmDeletion($user, $code);

        self::assertFalse($result, 'confirmDeletion must return false for expired code');
        self::assertSame(0, SpyDeletionHook::$callCount, 'Hook must NOT be called for expired code');

        $found = $this->fetchUser($email);
        self::assertNotNull($found, 'User must still exist after expired deletion attempt');
    }

    // ── (e) HTTP: delete-request route requires CSRF + auth ──────────────────

    public function testDeleteRequestRouteRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('POST', '/settings/delete-request', ['_token' => 'anything']);

        self::assertResponseRedirects('/login', 302);
    }

    // ── (f) HTTP: delete-request route sends code and redirects ──────────────

    public function testDeleteRequestRouteStoressCodeAndRedirects(): void
    {
        $email = 'http-req@example.com';
        $plain = 'securepass12345!';

        // createClient() must come before any getContainer() call
        $client = static::createClient();
        $this->createUser($email, $plain);
        $this->loginAs($client, $email, $plain);

        // Get valid CSRF token by loading the settings page
        $crawler = $client->request('GET', '/settings');
        self::assertResponseIsSuccessful();

        // Extract the CSRF token from THIS form, not from whichever form the
        // settings page happens to render first — the page carries several,
        // each with its own token id, and picking by document order breaks
        // silently the next time one is added above it.
        $token = $crawler->filter('form[action$="/settings/delete-request"] input[name="_token"]')->attr('value');

        // The password rides the request since 2026-08-13 (owner): an open
        // session on a shared machine must not be enough to start a deletion.
        $client->request('POST', '/settings/delete-request', ['_token' => $token, 'current_password' => $plain]);

        self::assertResponseRedirects('/settings?tab=security');
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Check your email');

        // Without the password (or with a wrong one) no code is sent.
        $crawler = $client->request('GET', '/settings');
        $token = $crawler->filter('form[action$="/settings/delete-request"] input[name="_token"]')->attr('value');
        $client->request('POST', '/settings/delete-request', ['_token' => $token, 'current_password' => 'wrong-password']);
        self::assertResponseRedirects('/settings?tab=security');
        $client->followRedirect();
        self::assertSelectorExists('.flash-error');

        $user = $this->fetchUser($email);
        self::assertNotNull($user);
        self::assertNotNull($user->getDeletionCode());
    }

    // ── (g) HTTP: confirm route with correct code deletes and redirects home ──

    public function testConfirmRouteWithCorrectCodeDeletesAndRedirectsHome(): void
    {
        $email = 'http-confirm@example.com';
        $plain = 'securepass12345!';

        // createClient() must come before any getContainer() call
        $client = static::createClient();
        $user = $this->createUser($email, $plain);

        // Set up the deletion code directly
        /** @var UserDeletionService $service */
        $service = static::getContainer()->get(UserDeletionService::class);
        $service->requestDeletion($user);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->refresh($user);
        $code = (string) $user->getDeletionCode();

        $this->loginAs($client, $email, $plain);

        // Get a confirm CSRF token
        $crawler = $client->request('GET', '/settings');
        self::assertResponseIsSuccessful();

        // Selected by the form's own action, not by position among the page's
        // several _token inputs: document order is not a contract.
        $confirmToken = $crawler->filter('form[action$="/settings/delete-confirm"] input[name="_token"]')->attr('value');

        $client->request('POST', '/settings/delete-confirm', [
            '_token' => $confirmToken,
            'deletion_code' => $code,
        ]);

        // Should redirect to home after deletion
        self::assertResponseRedirects();
        $location = $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/', (string) $location);

        $found = $this->fetchUser($email);
        self::assertNull($found, 'User must be deleted after confirmed HTTP deletion');
    }
}
