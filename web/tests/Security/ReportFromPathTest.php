<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The report form's "from" link is a path on this site or nothing. A browser
 * reads `/\host` as another host, and drops a tab or a newline before it
 * reads, so either one would put an off-site link under our label on the form
 * and on the curator desk (audit 2026-10-04).
 */
final class ReportFromPathTest extends WebTestCase
{
    public function testAPathOnThisSiteIsKept(): void
    {
        $client = static::createClient();
        $page = $client->request('GET', '/report/town/node-777001?from='.rawurlencode('/regions/noord-holland'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('a[href="/regions/noord-holland"]'));
        self::assertCount(1, $page->filter('input[name="from"][value="/regions/noord-holland"]'));
    }

    /** @return iterable<string, array{string}> */
    public static function offSite(): iterable
    {
        yield 'two slashes' => ['//evil.example/x'];
        yield 'slash and backslash' => ['/\evil.example/x'];
        yield 'a backslash later on' => ['/a\b'];
        yield 'a tab after the slash' => ["/\t/evil.example"];
        yield 'a newline after the slash' => ["/\n/evil.example"];
    }

    #[DataProvider('offSite')]
    public function testAnythingThatLeavesTheSiteIsDropped(string $from): void
    {
        $client = static::createClient();
        $page = $client->request('GET', '/report/town/node-777001?from='.rawurlencode($from));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('input[name="from"]'), 'no from path is carried');
        $links = $page->filter('a')->each(static fn ($a): string => (string) $a->attr('href'));
        self::assertNotContains($from, $links, 'and no link points at it');
    }
}
