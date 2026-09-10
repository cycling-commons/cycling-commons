<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Auth;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Context-aware navigation: anon sees "Log in"; authenticated user sees
 * Profile/Settings/Log out; curators see Moderate; admins see Admin (+ Moderate
 * via role hierarchy).
 *
 * ROLE_CURATOR and ROLE_ADMIN test users must have totpSecret set so the
 * TwoFactorSetupEnforcer lets them through to the page.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction.
 */
final class NavAuthTest extends WebTestCase
{
    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @param list<string> $roles
     */
    private function createUser(
        string $email,
        array $roles = [],
        ?string $totpSecret = null,
        bool $twoFaEnabled = false,
    ): User {
        $container = static::getContainer();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Test User');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, 'hunter2secure!'));

        if (null !== $totpSecret) {
            $user->setTotpSecret($totpSecret);
            $user->setTwoFaEnabled($twoFaEnabled);
        }

        $em->persist($user);
        $em->flush();

        return $user;
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    /**
     * Anonymous visitors see "Log in" and no role-gated links.
     */
    public function testAnonSeesLoginLink(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('nav', 'Log in');
        self::assertSelectorNotExists('a[href*="/profile"]');
        self::assertSelectorNotExists('a[href*="/settings"]');
        // logout is now a POST form button — no <a href="/logout"> for anon users
        self::assertSelectorNotExists('form[action*="/logout"]');
        self::assertSelectorNotExists('a[href*="/moderate"]');
        self::assertSelectorNotExists('a[href*="/admin"]');
    }

    /**
     * Plain ROLE_USER sees Profile/Settings/Log out; no Moderate or Admin.
     * Logout is rendered as a POST form (CSRF-protected), not a plain link.
     */
    public function testRoleUserSeesAccountLinks(): void
    {
        $client = static::createClient();

        $user = $this->createUser('rider@nav-test.example.com');
        $client->loginUser($user);

        $client->request('GET', '/about');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href*="/profile"]');
        self::assertSelectorExists('a[href*="/settings"]');
        // logout is a POST form with CSRF token, not a bare link
        self::assertSelectorExists('form[action*="/logout"]');
        self::assertSelectorExists('form[action*="/logout"] input[name="_csrf_token"]');
        self::assertSelectorExists('form[action*="/logout"] button[type="submit"]');
        self::assertSelectorNotExists('a[href*="/moderate"]');
        self::assertSelectorNotExists('a[href*="/admin"]');
    }

    /**
     * ROLE_CURATOR (with 2FA enrolled) sees Moderate but not Admin.
     */
    public function testRoleCuratorSeesModerateLinkOnly(): void
    {
        $client = static::createClient();

        $curator = $this->createUser(
            'curator@nav-test.example.com',
            roles: ['ROLE_CURATOR'],
            totpSecret: 'JBSWY3DPEHPK3PXP',
            twoFaEnabled: true,
        );
        $client->loginUser($curator);

        $client->request('GET', '/about');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href*="/moderate"]');
        self::assertSelectorNotExists('a[href*="/admin"]');
    }

    /**
     * ROLE_ADMIN (with 2FA enrolled) sees Admin AND Moderate (via role hierarchy).
     */
    public function testRoleAdminSeesAdminAndModerateLinks(): void
    {
        $client = static::createClient();

        $admin = $this->createUser(
            'admin@nav-test.example.com',
            roles: ['ROLE_ADMIN'],
            totpSecret: 'JBSWY3DPEHPK3PXP',
            twoFaEnabled: true,
        );
        $client->loginUser($admin);

        $client->request('GET', '/about');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href*="/admin"]');
        self::assertSelectorExists('a[href*="/moderate"]');
    }
}
