<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Support\BugArea;
use App\Support\BugStatus;
use App\Support\Entity\BugReport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The bug desk's two chip rows: status and category
 * (docs/specs/contact-and-support.md §9, "Filtering").
 *
 * Each row is labelled, each has an All chip, and a chip in one row keeps what
 * is picked in the other.
 */
final class BugDeskFiltersTest extends WebTestCase
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
        $user = (new User())->setEmail('bug-filters@cyclingcommons.org');
        $user->setPassword('x');
        $user->setDisplayName('Filters Desk Curator');
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

    private function bug(string $title, BugStatus $status, BugArea $area): BugReport
    {
        $report = new BugReport($title, 'Body of '.$title);
        $report->setStatus($status);
        $report->setArea($area);
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

    private function chipHref(Crawler $group, string $value): string
    {
        $chip = $group->filter('a.chip[data-value="'.$value.'"]');
        self::assertCount(1, $chip, 'a chip for '.$value);

        return (string) $chip->attr('href');
    }

    /** @return array<string, string> */
    private function queryOf(string $href): array
    {
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $q);

        /* @var array<string, string> $q */
        return $q;
    }

    public function testBothRowsAreLabelledGroupsWithAnAllChip(): void
    {
        $client = $this->client();
        $client->loginUser($this->curator());

        $page = $client->request('GET', '/moderate/bugs');
        self::assertResponseIsSuccessful();

        foreach (['status', 'area'] as $name) {
            $group = $this->group($page, $name);
            self::assertSame('group', $group->attr('role'));
            $labelId = (string) $group->attr('aria-labelledby');
            self::assertNotSame('', $labelId);
            self::assertCount(1, $page->filter('#'.$labelId), 'the row names itself');
            $this->chipHref($group, 'all');
        }
        self::assertSame('Status', trim($page->filter('#'.$this->group($page, 'status')->attr('aria-labelledby'))->text()));
        self::assertSame('Category', trim($page->filter('#'.$this->group($page, 'area')->attr('aria-labelledby'))->text()));
    }

    public function testAllStatusesListsEveryReport(): void
    {
        $client = $this->client();
        $this->bug('Grey map on load', BugStatus::New, BugArea::Map);
        $this->bug('Search forgot my town', BugStatus::Resolved, BugArea::Search);
        $client->loginUser($this->curator());

        $default = $client->request('GET', '/moderate/bugs');
        self::assertStringContainsString('Grey map on load', $default->text());
        self::assertStringNotContainsString('Search forgot my town', $default->text(), 'the desk still opens on New');

        $all = $client->request('GET', '/moderate/bugs?status=all');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Grey map on load', $all->text());
        self::assertStringContainsString('Search forgot my town', $all->text());
        self::assertSame('true', $this->group($all, 'status')->filter('a.chip[data-value="all"]')->attr('aria-current'));
    }

    public function testAStatusChipKeepsTheCategoryAndACategoryChipKeepsTheStatus(): void
    {
        $client = $this->client();
        $client->loginUser($this->curator());

        $page = $client->request('GET', '/moderate/bugs?status=resolved&area=map');
        self::assertResponseIsSuccessful();

        $status = $this->group($page, 'status');
        self::assertSame(['status' => 'planned', 'area' => 'map'], $this->queryOf($this->chipHref($status, 'planned')));
        self::assertSame(['status' => 'all', 'area' => 'map'], $this->queryOf($this->chipHref($status, 'all')));

        $area = $this->group($page, 'area');
        self::assertSame(['status' => 'resolved', 'area' => 'search'], $this->queryOf($this->chipHref($area, 'search')));
        self::assertSame(['status' => 'resolved'], $this->queryOf($this->chipHref($area, 'all')));
        self::assertSame('true', $area->filter('a.chip[data-value="map"]')->attr('aria-current'));
    }

    public function testStatusCountsFollowTheChosenCategory(): void
    {
        $client = $this->client();
        $this->bug('Grey map on load', BugStatus::New, BugArea::Map);
        $this->bug('Pins jump on zoom', BugStatus::New, BugArea::Map);
        $this->bug('Search forgot my town', BugStatus::New, BugArea::Search);
        $client->loginUser($this->curator());

        $page = $client->request('GET', '/moderate/bugs?status=all&area=map');
        $status = $this->group($page, 'status');
        self::assertSame('2', trim($status->filter('a.chip[data-value="new"] .n')->text()));
        self::assertSame('2', trim($status->filter('a.chip[data-value="all"] .n')->text()));
    }
}
