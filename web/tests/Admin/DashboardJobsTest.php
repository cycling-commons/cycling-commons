<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\User;
use App\Ops\DailyJobs;
use App\Ops\JobRunStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The admin dashboard lists the daily jobs with their last good run, and
 * warns at the top while any of them is late (docs/specs/operations.md).
 */
final class DashboardJobsTest extends WebTestCase
{
    private function admin(): KernelBrowser
    {
        $client = static::createClient();
        $u = (new User())->setEmail('admin-jobs@example.com')->setDisplayName('Admin Jobs');
        $u->setEmailVerified(true);
        $u->setEmailVerifiedAt(new \DateTimeImmutable());
        $u->setRoles(['ROLE_ADMIN']);
        $u->setTotpSecret('JBSWY3DPEHPK3PXP');
        $u->setTwoFaEnabled(true);
        $u->setPassword('x');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($u);
        $em->flush();
        $client->loginUser($u);

        return $client;
    }

    private function store(): JobRunStore
    {
        $store = static::getContainer()->get(JobRunStore::class);
        \assert($store instanceof JobRunStore);

        return $store;
    }

    public function testEveryDailyJobIsListed(): void
    {
        $client = $this->admin();
        $page = $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertCount(\count(DailyJobs::COMMANDS), $page->filter('[data-job]'));
    }

    public function testALateJobRaisesTheWarning(): void
    {
        $client = $this->admin();
        foreach (DailyJobs::COMMANDS as $command) {
            $this->store()->record($command, new \DateTimeImmutable());
        }
        $this->store()->record('app:media:gc', new \DateTimeImmutable('-2 days'));

        $page = $client->request('GET', '/admin');
        self::assertCount(1, $page->filter('[data-jobs-late]'));
        self::assertSame('late', $page->filter('[data-job="app:media:gc"]')->attr('data-job-state'));
    }

    public function testNoWarningWhileEveryJobRanInTime(): void
    {
        $client = $this->admin();
        foreach (DailyJobs::COMMANDS as $command) {
            $this->store()->record($command, new \DateTimeImmutable('-1 hour'));
        }

        $page = $client->request('GET', '/admin');
        self::assertCount(0, $page->filter('[data-jobs-late]'));
    }
}
