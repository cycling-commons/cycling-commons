<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Controller\Admin\AdminActionLogCrudController;
use App\Controller\Admin\BlogPostCrudController;
use App\Controller\Admin\DashboardController;
use App\Controller\Admin\ReleaseTagCrudController;
use App\Controller\Admin\ResetPasswordRequestCrudController;
use App\Controller\Admin\UserCrudController;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Every admin list shows each row's primary key in an "ID" column (owner
 * 2026-10-04), so a row on screen can be found in the database by its key.
 */
final class AdminIdColumnsTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $c = static::getContainer();
        $u = (new User())->setEmail('admin-ids@example.com')->setDisplayName('Admin Ids');
        $u->setEmailVerified(true);
        $u->setEmailVerifiedAt(new \DateTimeImmutable());
        $u->setRoles(['ROLE_ADMIN']);
        $u->setTotpSecret('JBSWY3DPEHPK3PXP');
        $u->setTwoFaEnabled(true); // fully enrolled, else the enforcer redirects to /2fa/setup
        $u->setPassword($c->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        $em = $c->get(EntityManagerInterface::class);
        $em->persist($u);
        $em->flush();
        $this->admin = $u;
        $this->client->loginUser($u);
    }

    /** @return iterable<string, array{class-string}> */
    public static function cruds(): iterable
    {
        yield 'users' => [UserCrudController::class];
        yield 'activity' => [AdminActionLogCrudController::class];
        yield 'reset requests' => [ResetPasswordRequestCrudController::class];
        yield 'releases' => [ReleaseTagCrudController::class];
        yield 'blog' => [BlogPostCrudController::class];
    }

    /**
     * The field list, not the page: an empty list renders no table header.
     *
     * @param class-string $controller
     */
    #[DataProvider('cruds')]
    public function testEveryCrudListStartsWithTheId(string $controller): void
    {
        $crud = static::getContainer()->get($controller);
        self::assertInstanceOf(AbstractCrudController::class, $crud);
        $first = null;
        foreach ($crud->configureFields(Crud::PAGE_INDEX) as $field) {
            $first = $field;
            break;
        }

        self::assertInstanceOf(IdField::class, $first);
        self::assertSame('id', $first->getAsDto()->getProperty());
    }

    public function testTheUserListShowsTheKey(): void
    {
        $url = static::getContainer()->get(AdminUrlGenerator::class)
            ->setDashboard(DashboardController::class)->setController(UserCrudController::class)->setAction(Crud::PAGE_INDEX)->generateUrl();
        $crawler = $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        $labels = array_values(array_filter($crawler->filter('table thead th')->each(static fn ($th): string => trim($th->text()))));
        self::assertSame('ID', $labels[0] ?? null, 'the ID is the first labelled column');
        $ids = $crawler->filter('table tbody tr td[data-column="id"]')->each(static fn ($td): string => trim($td->text()));
        self::assertContains((string) $this->admin->getId(), $ids);
    }

    public function testTheDashboardsRecentAccountsShowTheirKey(): void
    {
        $crawler = $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        $table = $crawler->filter('table.table')->last();
        self::assertSame('ID', trim($table->filter('thead th')->first()->text()));
        self::assertContains((string) $this->admin->getId(), $table->filter('tbody tr td:first-child')->each(static fn ($td): string => trim($td->text())));
    }
}
