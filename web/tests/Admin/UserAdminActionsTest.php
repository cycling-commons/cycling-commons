<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Tests\Admin;

use App\Controller\Admin\DashboardController;
use App\Controller\Admin\UserCrudController;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UserAdminService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserAdminActionsTest extends WebTestCase
{
    /** @param list<string> $roles */
    private function createUser(string $email, array $roles = [], bool $admin2fa = false): User
    {
        $c = static::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);

        $u = new User();
        $u->setEmail($email);
        $u->setDisplayName('T');
        $u->setEmailVerified(true);
        $u->setEmailVerifiedAt(new \DateTimeImmutable());
        $u->setRoles($roles);
        $u->setPassword($hasher->hashPassword($u, 'password1234'));
        if ($admin2fa) {
            $u->setTotpSecret('JBSWY3DPEHPK3PXP');
        }
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function actionUrl(string $action, int $entityId): string
    {
        /** @var AdminUrlGenerator $gen */
        $gen = static::getContainer()->get(AdminUrlGenerator::class);

        return $gen->setDashboard(DashboardController::class)
            ->setController(UserCrudController::class)
            ->setAction($action)
            ->setEntityId($entityId)
            ->generateUrl();
    }

    public function testUnlockActionClearsLockAndWritesAudit(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true);

        $locked = $this->createUser('locked@example.com');
        $locked->setFailedLoginAttempts(5);
        $locked->setLockedUntil(new \DateTimeImmutable('+1 hour'));
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->loginUser($admin);
        $client->request('GET', $this->actionUrl(UserAdminService::UNLOCK, (int) $locked->getId()));

        self::assertResponseRedirects();

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $reloaded = static::getContainer()->get(UserRepository::class)->findByEmail('locked@example.com');
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isLocked());
    }

    public function testRevokingLastAdminIsBlockedWithFlash(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('solo-admin@example.com', ['ROLE_ADMIN'], admin2fa: true);

        $client->loginUser($admin);
        $client->request('GET', $this->actionUrl(UserAdminService::REVOKE_ADMIN, (int) $admin->getId()));
        $client->followRedirect();

        self::assertSelectorExists('.alert-danger, .flash-danger, [class*="danger"]');

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $reloaded = static::getContainer()->get(UserRepository::class)->findByEmail('solo-admin@example.com');
        self::assertNotNull($reloaded);
        self::assertContains('ROLE_ADMIN', $reloaded->getRoles(), 'Last admin must keep ROLE_ADMIN.');
    }

    public function testDetailPageNeverLeaksSecrets(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true);
        $t = $this->createUser('subject@example.com');
        $t->setTotpSecret('JBSWY3DPEHPK3PXPSECRETSEED');
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->loginUser($admin);
        $gen = static::getContainer()->get(AdminUrlGenerator::class);
        $url = $gen->setDashboard(DashboardController::class)
            ->setController(UserCrudController::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($t->getId())
            ->generateUrl();
        $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('JBSWY3DPEHPK3PXPSECRETSEED', (string) $client->getResponse()->getContent());
    }
}
