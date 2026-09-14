<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Support\Entity\ContentReport;
use App\Support\ReportGround;
use App\Support\ReportStatus;
use App\Support\ReportTarget;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

/**
 * The reports desk's two chip rows, the same as the bug desk's
 * (docs/specs/content-reports.md §9, docs/specs/contact-and-support.md §9 "Filtering").
 */
final class ReportDeskFiltersTest extends WebTestCase
{
    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function curator(): User
    {
        $user = (new User())->setEmail('report-filters@cyclingcommons.org');
        $user->setPassword('x');
        $user->setDisplayName('Filters Report Curator');
        $user->setRoles(['ROLE_CURATOR']);
        // Elevated roles must be TOTP-enrolled or every page redirects to /2fa/setup.
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $user->setTwoFaEnabled(true);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function report(ReportTarget $target, string $reason, ?ReportStatus $taken = null): ContentReport
    {
        $report = new ContentReport(Uuid::v4(), $target, '1', ReportGround::Untrue, $reason, str_repeat('a', 64), new \DateTimeImmutable());
        if (null !== $taken && $taken->isDecided()) {
            $report->decide($taken, 'Decided in the test.', 1, new \DateTimeImmutable());
        } elseif (null !== $taken) {
            $report->takeUp($taken);
        }
        $this->em()->persist($report);
        $this->em()->flush();

        return $report;
    }

    private function group(Crawler $page, string $name): Crawler
    {
        $group = $page->filter('.chips[data-filter="'.$name.'"]');
        self::assertCount(1, $group, 'one '.$name.' row');

        return $group;
    }

    /** @return array<string, string> */
    private function queryOf(Crawler $group, string $value): array
    {
        $chip = $group->filter('a.chip[data-value="'.$value.'"]');
        self::assertCount(1, $chip, 'a chip for '.$value);
        parse_str((string) parse_url((string) $chip->attr('href'), \PHP_URL_QUERY), $q);
        ksort($q);   // which parameters, not their order in the URL

        /* @var array<string, string> $q */
        return $q;
    }

    public function testBothRowsAreLabelledGroupsWithAnAllChip(): void
    {
        $client = $this->client();
        $client->loginUser($this->curator());

        $page = $client->request('GET', '/moderate/reports');
        self::assertResponseIsSuccessful();

        foreach (['status' => 'Status', 'target' => 'Category'] as $name => $label) {
            $group = $this->group($page, $name);
            self::assertSame('group', $group->attr('role'));
            self::assertSame($label, trim($page->filter('#'.$group->attr('aria-labelledby'))->text()));
            $this->queryOf($group, 'all');
        }
        self::assertSame('true', $this->group($page, 'status')->filter('a.chip[data-value="open"]')->attr('aria-current'), 'the desk still opens on Open');
    }

    public function testAllStatusesListsEveryReport(): void
    {
        $client = $this->client();
        $open = $this->report(ReportTarget::Route, 'Still waiting on this one');
        $moot = $this->report(ReportTarget::Route, 'Already gone by the time we looked', ReportStatus::Moot);
        $client->loginUser($this->curator());

        $listed = static fn (Crawler $page, ContentReport $r): int => $page->filter('.rrow a[href$="/moderate/reports/'.$r->getId().'"]')->count();

        $default = $client->request('GET', '/moderate/reports');
        self::assertSame(1, $listed($default, $open));
        self::assertSame(0, $listed($default, $moot), 'the desk still opens on Open');

        $all = $client->request('GET', '/moderate/reports?status=all');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $listed($all, $open));
        self::assertSame(1, $listed($all, $moot));
    }

    public function testAChipKeepsTheOtherRowsChoice(): void
    {
        $client = $this->client();
        $client->loginUser($this->curator());

        $page = $client->request('GET', '/moderate/reports?status=upheld&target=item');
        self::assertResponseIsSuccessful();

        $status = $this->group($page, 'status');
        self::assertSame(['status' => 'rejected', 'target' => 'item'], $this->queryOf($status, 'rejected'));
        self::assertSame(['status' => 'all', 'target' => 'item'], $this->queryOf($status, 'all'));

        $target = $this->group($page, 'target');
        self::assertSame(['status' => 'upheld', 'target' => 'route'], $this->queryOf($target, 'route'));
        self::assertSame(['status' => 'upheld'], $this->queryOf($target, 'all'));
    }

    /** Open lists every report still waiting, taken up or not, so its number counts both. */
    public function testCountsFollowTheCategoryAndOpenCountsWhatItLists(): void
    {
        $client = $this->client();
        $this->report(ReportTarget::Route, 'Route one');
        $this->report(ReportTarget::Route, 'Route two', ReportStatus::InProgress);
        $this->report(ReportTarget::Item, 'An item');
        $client->loginUser($this->curator());

        $page = $client->request('GET', '/moderate/reports?target=route');
        $status = $this->group($page, 'status');
        self::assertSame('2', trim($status->filter('a.chip[data-value="open"] .n')->text()));
        self::assertSame('1', trim($status->filter('a.chip[data-value="in_progress"] .n')->text()));
        self::assertSame('2', trim($status->filter('a.chip[data-value="all"] .n')->text()));
    }
}
