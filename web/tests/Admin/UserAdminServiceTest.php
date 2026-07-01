<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
        $u->setDisplayName('T');
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
        // Remove `other` first so `admin`… actually keep admin as the sole admin target:
        $lastAdmin = $this->user('last@example.com', ['ROLE_ADMIN']);
        // With three admins, revoking one is fine; drop two to leave `lastAdmin` alone.
        $this->svc->revokeAdmin($admin, $lastAdmin);
        $this->svc->revokeAdmin($other, $lastAdmin);

        $this->expectException(GuardrailViolationException::class);
        $this->svc->revokeAdmin($lastAdmin, $lastAdmin); // now the only admin
    }
}
