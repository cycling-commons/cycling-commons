<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Community\CountryInterestService;
use App\Entity\User;
use App\Service\AdminQueueCounts;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The admin dashboard shows what is waiting for somebody, each count linking
 * to the desk that handles it (owner 2026-10-11).
 *
 * @see docs/specs/account-and-auth.md §6.6
 */
final class DashboardQueuesTest extends WebTestCase
{
    public function testEveryQueueIsOnTheDashboardWithItsCountAndDesk(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = (new User())->setEmail('queues-admin@example.com')->setDisplayName('Queues Admin');
        $admin->setPassword('x');
        $admin->setEmailVerified(true);
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setTotpSecret('JBSWY3DPEHPK3PXP');
        $admin->setTwoFaEnabled(true);
        $em->persist($admin);
        $rider = (new User())->setEmail('queues-rider@example.com')->setDisplayName('Queues Rider');
        $rider->setPassword('x');
        $em->persist($rider);
        $em->flush();

        $queues = static::getContainer()->get(AdminQueueCounts::class);
        $before = $queues->collect()['countryRequests'];
        static::getContainer()->get(CountryInterestService::class)->record($rider, 'DK', false, '');
        $counts = $queues->collect();
        self::assertSame($before + 1, $counts['countryRequests'], 'a new request is open');

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $desks = [
            'country_requests' => ['/admin/country-requests', $counts['countryRequests']],
            'curator_apps' => ['/admin/curator-applications', $counts['curatorApplications']],
            'escalated_submissions' => ['/admin/escalated', $counts['escalatedSubmissions']],
            'escalated_photos' => ['/admin/escalated', $counts['escalatedPhotos']],
            'reports' => ['/moderate/reports', $counts['reports']],
        ];
        foreach ($desks as $key => [$href, $count]) {
            $link = $crawler->filter('[data-queues] a[data-queue="'.$key.'"]');
            self::assertCount(1, $link, $key.' is on the dashboard');
            self::assertStringEndsWith($href, (string) $link->attr('href'), $key.' links its desk');
            self::assertSame((string) $count, $link->attr('data-count'), $key.' carries its count');
            // Waiting work is a row with its number; an empty queue folds into the quiet line.
            self::assertCount($count > 0 ? 1 : 0, $crawler->filter('.ccd-queue a[data-queue="'.$key.'"]'), $key.' sits where its count puts it');
        }
        self::assertSame((string) $counts['countryRequests'], trim($crawler->filter('.ccd-queue a[data-queue="country_requests"] .ccd-n')->text()),
            'a queue with something waiting shows its number');
    }
}
