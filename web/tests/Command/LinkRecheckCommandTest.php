<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Command;

use App\Catalog\Command\LinkRecheckCommand;
use App\Catalog\Links\LinkVerdictStore;
use App\Catalog\Links\SafeBrowsing;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * `app:links:recheck` (test-suite review 2026-08-24).
 *
 * SafeBrowsing and LinkVerdictStore each have their own tests. The command
 * that joins them did not, and it carries the one rule neither service can
 * enforce alone: **with the reputation layer off, re-check nothing and record
 * nothing.** Writing UNKNOWN rows in that state would stamp "checked" on urls
 * nobody ever checked, and every later `stale()` query would then skip them.
 *
 * No network: the enabled path is driven with a MockHttpClient, and the
 * allowlist case never reaches HTTP at all by design.
 *
 * @see docs/specs/operations.md §1
 */
final class LinkRecheckCommandTest extends KernelTestCase
{
    private Connection $db;
    private LinkVerdictStore $store;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->store = static::getContainer()->get(LinkVerdictStore::class);
        $this->db->executeStatement("DELETE FROM link_verdict WHERE url LIKE '%recheck-cmd.test%' OR url LIKE '%wikipedia.org/wiki/Recheck%'");
    }

    public function testWithTheLayerOffNothingIsCheckedAndNothingIsRecorded(): void
    {
        $url = 'https://recheck-cmd.test/off';
        $this->seedStale($url, SafeBrowsing::SAFE);
        $before = $this->checkedAt($url);

        $tester = $this->run_(new SafeBrowsing(new MockHttpClient([]), new NullLogger(), ''));

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('reputation layer is OFF', $tester->getDisplay());
        // Same timestamp: the row was not touched, so it stays visibly stale
        // and gets re-checked for real the moment a key exists.
        self::assertSame($before, $this->checkedAt($url));
    }

    public function testAStaleAllowlistedUrlIsRefreshedWithoutAnyHttpCall(): void
    {
        // Allowlisted hosts are answered from the allowlist, never asked about.
        // An empty MockHttpClient makes that a hard assertion: any outbound
        // request would throw.
        $url = 'https://en.wikipedia.org/wiki/Recheck_fixture';
        $this->seedStale($url, SafeBrowsing::UNKNOWN);

        $tester = $this->run_(new SafeBrowsing(new MockHttpClient([]), new NullLogger(), 'key'));

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('re-checked', $tester->getDisplay());
        self::assertSame(SafeBrowsing::SAFE, $this->verdictOf($url));
    }

    public function testAStaleUrlThatGoogleFlagsIsRecordedUnsafeAndPrinted(): void
    {
        $url = 'https://recheck-cmd.test/bad';
        $this->seedStale($url, SafeBrowsing::SAFE);

        $client = new MockHttpClient(new MockResponse(json_encode([
            'matches' => [['threat' => ['url' => $url]]],
        ], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]));

        $tester = $this->run_(new SafeBrowsing($client, new NullLogger(), 'key'));

        $tester->assertCommandIsSuccessful();
        self::assertSame(SafeBrowsing::UNSAFE, $this->verdictOf($url));
        // A newly unsafe link is the only thing in this job worth a human's
        // attention, so it is named in the output, not just counted.
        self::assertStringContainsString($url, $tester->getDisplay());
        self::assertStringContainsString('1 unsafe', $tester->getDisplay());
    }

    public function testAFreshVerdictIsNotReChecked(): void
    {
        $url = 'https://recheck-cmd.test/fresh';
        $this->store->record([$url => SafeBrowsing::SAFE]);
        $before = $this->checkedAt($url);

        $tester = $this->run_(new SafeBrowsing(new MockHttpClient([]), new NullLogger(), 'key'));

        $tester->assertCommandIsSuccessful();
        // Re-asking about everything on every run is how a daily job turns into
        // a quota problem.
        self::assertSame($before, $this->checkedAt($url));
    }

    public function testTheLimitOptionBoundsOneRun(): void
    {
        foreach (['a', 'b', 'c'] as $n) {
            $this->seedStale('https://en.wikipedia.org/wiki/Recheck_'.$n, SafeBrowsing::UNKNOWN);
        }

        $tester = $this->run_(new SafeBrowsing(new MockHttpClient([]), new NullLogger(), 'key'), ['--limit' => '1']);

        $tester->assertCommandIsSuccessful();
        // One batch is one bounded unit of work; the rest waits for the next run.
        self::assertStringContainsString('1 url(s) re-checked', $tester->getDisplay());
    }

    /** @param array<string, mixed> $args */
    private function run_(SafeBrowsing $safeBrowsing, array $args = []): CommandTester
    {
        $tester = new CommandTester(new LinkRecheckCommand($safeBrowsing, $this->store));
        $tester->execute($args);

        return $tester;
    }

    /** Record a verdict, then backdate it past the staleness window. */
    private function seedStale(string $url, string $verdict): void
    {
        $this->store->record([$url => $verdict]);
        $this->db->executeStatement(
            "UPDATE link_verdict SET checked_at = NOW() - INTERVAL '2 years' WHERE url_hash = :hash",
            ['hash' => LinkVerdictStore::hash($url)],
        );
    }

    private function verdictOf(string $url): string
    {
        return (string) $this->db->fetchOne(
            'SELECT verdict FROM link_verdict WHERE url_hash = :hash',
            ['hash' => LinkVerdictStore::hash($url)],
        );
    }

    private function checkedAt(string $url): string
    {
        return (string) $this->db->fetchOne(
            'SELECT checked_at FROM link_verdict WHERE url_hash = :hash',
            ['hash' => LinkVerdictStore::hash($url)],
        );
    }
}
