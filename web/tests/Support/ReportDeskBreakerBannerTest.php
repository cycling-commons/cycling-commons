<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Media\UrgentWithholdBreaker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Urgent photo reports are decided on the Reports desk, so that desk is where
 * a curator has to learn the auto-withhold budget is spent.
 *
 * @see docs/specs/photo-uploads.md §6c
 * @see docs/specs/content-reports.md §9
 */
final class ReportDeskBreakerBannerTest extends WebTestCase
{
    private const string BANNER = 'Reports are arriving too fast to be genuine.';

    private function curatorClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('breaker-banner@example.test');
        $user->setPassword('x');
        $user->setDisplayName('Breaker Banner Curator');
        $user->setRoles(['ROLE_CURATOR']);
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $user->setTwoFaEnabled(true);
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        return $client;
    }

    public function testTheReportsDeskSaysWhenTheBreakerIsOpen(): void
    {
        $client = $this->curatorClient();

        $breaker = static::getContainer()->get(UrgentWithholdBreaker::class);
        $guard = 0;
        while ($breaker->allowWithhold()) {
            self::assertLessThan(1000, ++$guard, 'the breaker never opened');
        }
        self::assertTrue($breaker->isOpen());

        $page = $client->request('GET', '/moderate/reports');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[role="alert"]:contains("'.self::BANNER.'")'));
    }

    public function testAClosedBreakerShowsNoBanner(): void
    {
        $client = $this->curatorClient();
        self::assertFalse(static::getContainer()->get(UrgentWithholdBreaker::class)->isOpen());

        $page = $client->request('GET', '/moderate/reports');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::BANNER, $page->text());
    }
}
