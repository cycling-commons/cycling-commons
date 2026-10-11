<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Controller\Admin\DashboardController;
use App\Controller\Admin\UserCrudController;
use App\Entity\User;
use App\World\Entity\Country;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The user list shows each rider's profile country and whether they are on
 * the newsletter (owner 2026-10-11).
 *
 * @see docs/specs/account-and-auth.md §6.6
 */
final class UserListProfileColumnsTest extends WebTestCase
{
    public function testTheListShowsTheProfileCountryAndTheNewsletter(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = (new User())->setEmail('list-admin@example.com')->setDisplayName('List Admin');
        $admin->setPassword('x');
        $admin->setEmailVerified(true);
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setTotpSecret('JBSWY3DPEHPK3PXP');
        $admin->setTwoFaEnabled(true);
        $em->persist($admin);

        $belgium = $em->getRepository(Country::class)->findOneBy(['iso2' => 'BE']);
        self::assertInstanceOf(Country::class, $belgium, 'the test database holds the world reference data');
        $rider = (new User())->setEmail('list-rider@example.com')->setDisplayName('List Rider');
        $rider->setPassword('x');
        $rider->setCountry($belgium);
        $rider->setUpdatesOptIn(true);
        $em->persist($rider);
        $em->flush();
        $client->loginUser($admin);

        $url = static::getContainer()->get(AdminUrlGenerator::class)
            ->setDashboard(DashboardController::class)->setController(UserCrudController::class)->setAction(Crud::PAGE_INDEX)->generateUrl();
        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        $labels = $crawler->filter('table thead th')->each(static fn ($th): string => trim($th->text()));
        self::assertContains('Country', $labels);
        self::assertContains('Newsletter', $labels);

        $row = $crawler->filter('table tbody tr')->reduce(static fn ($tr): bool => str_contains($tr->text(), 'list-rider@example.com'));
        self::assertCount(1, $row);
        self::assertSame($belgium->getName(), trim($row->filter('td[data-column="country"]')->text()));
        self::assertStringContainsStringIgnoringCase('yes', $row->filter('td[data-column="updatesOptIn"]')->text());

        $adminRow = $crawler->filter('table tbody tr')->reduce(static fn ($tr): bool => str_contains($tr->text(), 'list-admin@example.com'));
        self::assertStringContainsStringIgnoringCase('no', $adminRow->filter('td[data-column="updatesOptIn"]')->text());
    }
}
