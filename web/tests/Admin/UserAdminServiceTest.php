<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Admin;

use App\Entity\User;
use App\Service\GuardrailViolationException;
use App\Service\UserAdminService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserAdminServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserAdminService $svc;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->svc = $c->get(UserAdminService::class);
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles = []): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setDisplayName(strstr($email, '@', true) ?: $email);
        $u->setPassword('x');
        $u->setRoles($roles);
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    public function testUnlockClearsLockAndCounter(): void
    {
        $admin = $this->user('a@example.com', ['ROLE_ADMIN']);
        $t = $this->user('t@example.com');
        $t->setFailedLoginAttempts(5);
        $t->setLockedUntil(new \DateTimeImmutable('+1 hour'));
        $this->em->flush();

        $this->svc->unlock($t, $admin);

        self::assertSame(0, $t->getFailedLoginAttempts());
        self::assertNull($t->getLockedUntil());
        self::assertFalse($t->isLocked());
    }

    public function testDisarmTwoFaClearsSecretAndCodes(): void
    {
        $admin = $this->user('a@example.com', ['ROLE_ADMIN']);
        $t = $this->user('t@example.com');
        $t->setTwoFaEnabled(true);
        $t->setTotpSecret('JBSWY3DPEHPK3PXP');
        $t->setBackupCodes(['hash1', 'hash2']);
        $this->em->flush();

        $this->svc->disarmTwoFa($t, $admin);

        self::assertFalse($t->isTwoFaEnabled());
        self::assertNull($t->getTotpSecret());
        self::assertSame([], $t->getBackupCodes());
    }

    public function testVerifyAndUnverifyEmail(): void
    {
        $admin = $this->user('a@example.com', ['ROLE_ADMIN']);
        $t = $this->user('t@example.com');
        $t->setEmailVerified(false);
        $this->em->flush();

        $this->svc->verifyEmail($t, $admin);
        self::assertTrue($t->isEmailVerified());
        self::assertNotNull($t->getEmailVerifiedAt());

        $this->svc->unverifyEmail($t, $admin);
        self::assertFalse($t->isEmailVerified());
        self::assertNull($t->getEmailVerifiedAt());
    }

    public function testGrantAndRevokeRolesNeverStoreRoleUser(): void
    {
        $admin = $this->user('a@example.com', ['ROLE_ADMIN']);
        $keepAdmin = $this->user('keep@example.com', ['ROLE_ADMIN']); // ensures not last admin
        $t = $this->user('t@example.com');

        $this->svc->grantCurator($t, $admin);
        self::assertContains('ROLE_CURATOR', $t->getRoles());

        $this->svc->grantAdmin($t, $admin);
        self::assertContains('ROLE_ADMIN', $t->getRoles());

        $this->svc->revokeAdmin($t, $admin);
        self::assertNotContains('ROLE_ADMIN', $t->getRoles());

        $this->svc->revokeCurator($t, $admin);
        self::assertNotContains('ROLE_CURATOR', $t->getRoles());

        $stored = $this->em->getConnection()->fetchOne('SELECT roles FROM users WHERE id = ?', [$t->getId()]);
        self::assertStringNotContainsString('ROLE_USER', (string) $stored);
    }

    public function testCannotRevokeOwnAdmin(): void
    {
        $admin = $this->user('a@example.com', ['ROLE_ADMIN']);
        $this->user('keep@example.com', ['ROLE_ADMIN']); // second admin so "last admin" isn't the blocker

        $this->expectException(GuardrailViolationException::class);
        $this->svc->revokeAdmin($admin, $admin);
    }

    public function testCannotRevokeLastAdmin(): void
    {
        $admin = $this->user('a@example.com', ['ROLE_ADMIN']);
        $other = $this->user('other@example.com', ['ROLE_ADMIN']);
        // Drain down to a single admin ($lastAdmin) by revoking the other two,
        // then verify revoking the sole remaining admin is blocked.
        $lastAdmin = $this->user('last@example.com', ['ROLE_ADMIN']);
        // With three admins, revoking one is fine; drop two to leave `lastAdmin` alone.
        $this->svc->revokeAdmin($admin, $lastAdmin);
        $this->svc->revokeAdmin($other, $lastAdmin);

        $this->expectException(GuardrailViolationException::class);
        $this->svc->revokeAdmin($lastAdmin, $lastAdmin); // now the only admin
    }

    public function testRemoveAccountDeletesRowAndKeepsAuditTrail(): void
    {
        $admin = $this->user('a@example.com', ['ROLE_ADMIN']);
        $this->user('keep@example.com', ['ROLE_ADMIN']); // not last admin
        $t = $this->user('gone@example.com');
        $t->setDeletionRequestedAt(new \DateTimeImmutable());
        $this->em->flush();
        $id = $t->getId();

        $this->svc->removeAccount($t, $admin);
        $this->em->clear();

        self::assertNull($this->em->getRepository(User::class)->find($id), 'User row must be gone.');

        $logs = static::getContainer()->get(\App\Repository\AdminActionLogRepository::class)
            ->findBy(['action' => UserAdminService::REMOVE_ACCOUNT]);
        self::assertCount(1, $logs);
        self::assertStringContainsString('gone@example.com', (string) $logs[0]->getNote());
        self::assertNull($logs[0]->getTargetUser(), 'FK is SET NULL after the target row is removed.');
    }

    public function testRemoveAccountRunsDeletionHooks(): void
    {
        \App\Tests\Auth\SpyDeletionHook::reset();

        $admin = $this->user('a@example.com', ['ROLE_ADMIN']);
        $this->user('keep@example.com', ['ROLE_ADMIN']);
        $t = $this->user('hooked@example.com');
        $t->setDeletionRequestedAt(new \DateTimeImmutable());
        $this->em->flush();

        $before = \App\Tests\Auth\SpyDeletionHook::$callCount;
        $this->svc->removeAccount($t, $admin);

        // #41: admin removal must run the same UserDeletionHookInterface seam as
        // self-service deletion — not a bare $em->remove().
        self::assertSame($before + 1, \App\Tests\Auth\SpyDeletionHook::$callCount);
    }

    public function testRemoveAccountRollsBackMutationAndAuditWhenHookFails(): void
    {
        \App\Tests\Auth\SpyDeletionHook::reset();
        \App\Tests\Auth\SpyDeletionHook::$throwOnPreDelete = true;

        $admin = $this->user('a@example.com', ['ROLE_ADMIN']);
        $this->user('keep@example.com', ['ROLE_ADMIN']);
        $t = $this->user('survivor@example.com');
        $t->setDeletionRequestedAt(new \DateTimeImmutable());
        $this->em->flush();
        $id = $t->getId();

        try {
            $this->svc->removeAccount($t, $admin);
            self::fail('expected the hook failure to propagate');
        } catch (\RuntimeException) {
            // expected
        }
        \App\Tests\Auth\SpyDeletionHook::reset();
        $this->em->clear();

        // #15: mutation + audit are one transaction — a failure rolls BOTH back.
        self::assertNotNull($this->em->getRepository(User::class)->find($id), 'the user row must survive a rolled-back removal');
        $logs = static::getContainer()->get(\App\Repository\AdminActionLogRepository::class)
            ->findBy(['action' => UserAdminService::REMOVE_ACCOUNT]);
        self::assertCount(0, $logs, 'no audit row may persist when the removal rolled back');
    }

    public function testCannotRemoveOwnAccount(): void
    {
        $admin = $this->user('a@example.com', ['ROLE_ADMIN']);
        $this->user('keep@example.com', ['ROLE_ADMIN']);

        $this->expectException(GuardrailViolationException::class);
        $this->svc->removeAccount($admin, $admin);
    }

    public function testCancelPendingRemovalClearsFields(): void
    {
        $admin = $this->user('a@example.com', ['ROLE_ADMIN']);
        $t = $this->user('t@example.com');
        $t->setDeletionRequestedAt(new \DateTimeImmutable());
        $t->setDeletionCode('ABC123');
        $this->em->flush();

        $this->svc->cancelPendingRemoval($t, $admin);

        self::assertNull($t->getDeletionRequestedAt());
        self::assertNull($t->getDeletionCode());
    }
}
