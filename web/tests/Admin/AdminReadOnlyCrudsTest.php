<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Controller\Admin\AdminActionLogCrudController;
use App\Controller\Admin\DashboardController;
use App\Controller\Admin\ResetPasswordRequestCrudController;
use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminReadOnlyCrudsTest extends WebTestCase
{
    private function admin(): User
    {
        $c = static::getContainer();
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $em = $c->get(EntityManagerInterface::class);
        $u = new User();
        $u->setEmail('admin@example.com');
        $u->setDisplayName('A');
        $u->setEmailVerified(true);
        $u->setEmailVerifiedAt(new \DateTimeImmutable());
        $u->setRoles(['ROLE_ADMIN']);
        $u->setTotpSecret('JBSWY3DPEHPK3PXP');
        $u->setTwoFaEnabled(true); // fully enrolled, else the enforcer redirects to /2fa/setup
        $u->setPassword($hasher->hashPassword($u, 'password1234'));
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function indexUrl(string $controller): string
    {
        return static::getContainer()->get(AdminUrlGenerator::class)
            ->setDashboard(DashboardController::class)
            ->setController($controller)
            ->setAction(Crud::PAGE_INDEX)
            ->generateUrl();
    }

    public function testActivityIndexLoadsForAdmin(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());
        $client->request('GET', $this->indexUrl(AdminActionLogCrudController::class));
        self::assertResponseIsSuccessful();
    }

    public function testResetRequestIndexIsReadOnlyAndHidesSecrets(): void
    {
        $client = static::createClient();
        $admin = $this->admin();

        // Seed one reset request with a recognisable selector/token.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $req = new ResetPasswordRequest($admin, new \DateTimeImmutable('+1 hour'), 'SELECTORSECRET', 'HASHEDTOKENSECRET');
        $em->persist($req);
        $em->flush();

        $client->loginUser($admin);
        $client->request('GET', $this->indexUrl(ResetPasswordRequestCrudController::class));

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('SELECTORSECRET', $html);
        self::assertStringNotContainsString('HASHEDTOKENSECRET', $html);
        // Read-only: no "Create/New" action link in the toolbar.
        self::assertSelectorNotExists('.global-actions a.action-new');
    }
}
