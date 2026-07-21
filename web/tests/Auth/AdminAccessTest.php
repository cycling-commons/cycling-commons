<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Tests\Auth;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Admin dashboard access control: anon → login redirect, ROLE_USER → 403,
 * ROLE_ADMIN with 2FA enrolled → 200.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back transaction.
 */
final class AdminAccessTest extends WebTestCase
{
    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Create a user in the DB and return the entity.
     *
     * @param list<string> $roles roles to assign (excluding the implicit ROLE_USER)
     */
    private function createUser(
        string $email,
        string $plain,
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
        $user->setPassword($hasher->hashPassword($user, $plain));

        if (null !== $totpSecret) {
            $user->setTotpSecret($totpSecret);
            $user->setTwoFaEnabled($twoFaEnabled);
        }

        $em->persist($user);
        $em->flush();

        return $user;
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    /** Anonymous GET /admin must redirect to the login page. */
    public function testAnonRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin');

        self::assertResponseRedirects('/login', 302);
    }

    /** A plain ROLE_USER must receive a 403 when accessing /admin. */
    public function testRoleUserForbidden(): void
    {
        $client = static::createClient();

        $user = $this->createUser('rider@example.com', 'hunter2secure!');
        $client->loginUser($user);

        $client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * A ROLE_ADMIN user who has already enrolled in 2FA (totpSecret set) must
     * be able to access /admin and receive a 200 response.
     *
     * loginUser() bypasses the form-login flow and issues a fully authenticated
     * token directly. The TwoFactorSetupEnforcer still fires on every request;
     * having a non-null totpSecret satisfies its check and allows the request
     * to proceed to the EasyAdmin dashboard.
     */
    public function testRoleAdminWithTotpCanAccess(): void
    {
        $client = static::createClient();

        $admin = $this->createUser(
            'admin@example.com',
            'hunter2secure!',
            roles: ['ROLE_ADMIN'],
            totpSecret: 'JBSWY3DPEHPK3PXP',
            twoFaEnabled: true,
        );
        $client->loginUser($admin);

        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
    }

    /**
     * The email-change playbook page (account-and-auth.md §8 "Support
     * playbook") renders for an enrolled admin and carries the verification
     * script; it is admin-gated like every /admin route.
     */
    public function testEmailChangePlaybookRendersForAdmin(): void
    {
        $client = static::createClient();

        $admin = $this->createUser(
            'admin-playbook@example.com',
            'hunter2secure!',
            roles: ['ROLE_ADMIN'],
            totpSecret: 'JBSWY3DPEHPK3PXP',
            twoFaEnabled: true,
        );
        $client->loginUser($admin);

        $client->request('GET', '/admin/playbook/email-change');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Playbook: email-change requests', $html);
        self::assertStringContainsString('Never trust the request mail', $html);
        self::assertStringContainsString('No anchor left', $html);
    }

    /** The playbook page must stay behind the ROLE_ADMIN gate. */
    public function testEmailChangePlaybookForbiddenForRoleUser(): void
    {
        $client = static::createClient();

        $user = $this->createUser('rider-playbook@example.com', 'hunter2secure!');
        $client->loginUser($user);

        $client->request('GET', '/admin/playbook/email-change');

        self::assertResponseStatusCodeSame(403);
    }
}
