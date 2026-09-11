<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\GitHubContributors;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The GitHub half of the contributor count, checked without the network:
 * MockHttpClient replays the search API and the profile API, and the
 * assertions are about what the daily cron leaves behind — one row per
 * login, a window that only ever widens, the profile email stored for the
 * merge, and honest "new" counts.
 */
final class GitHubContributorsTest extends KernelTestCase
{
    private Connection $db;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
    }

    public function testSyncStoresOneRowPerMergedApprovedAuthor(): void
    {
        $service = new GitHubContributors(
            $this->http(
                [
                    ['login' => 'alice', 'merged' => '2026-09-05T10:00:00Z'],
                    ['login' => 'bob', 'merged' => '2026-09-06T10:00:00Z'],
                    ['login' => 'alice', 'merged' => '2026-09-08T10:00:00Z'],
                ],
                ['alice' => 'Alice@Example.org'],
            ),
            $this->db,
            'cycling-commons/cycling-commons',
            'tok',
        );

        $result = $service->sync();

        self::assertSame(2, $result['contributors']);
        self::assertSame(2, $result['added']);
        $window = $this->db->fetchAssociative('SELECT * FROM github_contributor WHERE github_login = \'alice\'');
        self::assertNotFalse($window);
        // One row per login, the window spans both merges, and the profile
        // email is stored lower-cased for the merge with site accounts.
        self::assertStringStartsWith('2026-09-05', (string) $window['first_pr_at']);
        self::assertStringStartsWith('2026-09-08', (string) $window['last_pr_at']);
        self::assertSame('alice@example.org', $window['email']);
        // Bob publishes no email: the row survives, the key falls back to login.
        self::assertNull($this->db->fetchOne('SELECT email FROM github_contributor WHERE github_login = \'bob\''));
    }

    public function testReSyncOnlyEverWidensTheWindow(): void
    {
        $this->service($this->http([
            ['login' => 'alice', 'merged' => '2026-09-08T10:00:00Z'],
        ]))->sync();

        // A later cron run sees the older PR too (GitHub's ordering is not a
        // promise across days). LEAST/GREATEST must recover the true window.
        $result = $this->service($this->http([
            ['login' => 'alice', 'merged' => '2026-09-01T10:00:00Z'],
            ['login' => 'alice', 'merged' => '2026-09-08T10:00:00Z'],
        ]))->sync();

        self::assertSame(1, $result['contributors']);
        self::assertSame(0, $result['added']);
        $window = $this->db->fetchAssociative('SELECT * FROM github_contributor WHERE github_login = \'alice\'');
        self::assertStringStartsWith('2026-09-01', (string) $window['first_pr_at']);
        self::assertStringStartsWith('2026-09-08', (string) $window['last_pr_at']);
    }

    public function testUnconfiguredRepoIsNotConfigured(): void
    {
        $service = new GitHubContributors(new MockHttpClient(), $this->db, '', '');

        self::assertFalse($service->isConfigured());
        $this->expectException(\RuntimeException::class);
        $service->sync();
    }

    #[DataProvider('provideInvalidRepos')]
    public function testMalformedRepoNamesAreRefused(string $repo): void
    {
        $service = new GitHubContributors(new MockHttpClient(), $this->db, $repo, 'tok');

        self::assertFalse($service->isConfigured());
    }

    /** @return iterable<array{string}> */
    public static function provideInvalidRepos(): iterable
    {
        yield 'empty' => [''];
        yield 'no owner' => ['cycling-commons'];
        yield 'path traversal' => ['../../somewhere'];
        yield 'spaces' => ['a b/c d'];
    }

    /**
     * @param list<array{login:string, merged:string}> $prs
     * @param array<string, string>                    $emails login => public profile email
     */
    private function http(array $prs, array $emails = []): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url) use ($prs, $emails): MockResponse {
            if (str_contains($url, '/search/issues')) {
                return new MockResponse(json_encode([
                    'total_count' => \count($prs),
                    'items' => array_map(static fn (array $pr): array => [
                        'user' => ['login' => $pr['login']],
                        'closed_at' => $pr['merged'],
                    ], $prs),
                ], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
            }

            $login = basename((string) parse_url($url, \PHP_URL_PATH));

            return new MockResponse(json_encode(['email' => $emails[$login] ?? null]), ['http_code' => 200]);
        });
    }

    private function service(MockHttpClient $http): GitHubContributors
    {
        return new GitHubContributors($http, $this->db, 'cycling-commons/cycling-commons', 'tok');
    }
}
