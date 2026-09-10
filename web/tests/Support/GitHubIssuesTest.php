<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\BugArea;
use App\Support\BugSeverity;
use App\Support\Entity\BugReport;
use App\Support\GitHubIssues;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Two fields leave, a number comes back (contact-and-support.md §9).
 */
final class GitHubIssuesTest extends TestCase
{
    public function testOnlyThePublicTitleAndBodyLeaveAndTheNumberComesBack(): void
    {
        $seen = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'body' => (string) $options['body']];

            return new MockResponse(json_encode(['number' => 142], \JSON_THROW_ON_ERROR), ['http_code' => 201]);
        });
        $issues = new GitHubIssues($client, 'cycling/commons', 'k-1', 'https://example.test/');
        $report = $this->bug();
        $report->setPublic(true);
        $report->setPublicTitle('The sitemap leaves out the blog');
        $report->setPublicBody('No blog posts in /sitemap.xml.');
        $report->setInternalNote('reporter is a friend, go easy');
        $report->setReporterEmail('somebody@example.test');

        self::assertSame(142, $issues->open($report));
        self::assertSame('POST', $seen['method']);
        self::assertSame('https://api.github.com/repos/cycling/commons/issues', $seen['url']);
        /** @var array{title: string, body: string, labels: list<string>} $sent */
        $sent = json_decode($seen['body'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('The sitemap leaves out the blog', $sent['title']);
        self::assertStringContainsString('No blog posts', $sent['body']);
        self::assertStringContainsString('https://example.test/known-issues', $sent['body']);
        self::assertSame(['title', 'body', 'labels'], array_keys($sent), 'two fields and a label, nothing else');
        self::assertStringNotContainsString('go easy', $seen['body'], 'the internal note never leaves');
        self::assertStringNotContainsString('somebody@example.test', $seen['body'], 'nor the address');
    }

    public function testAPrivateBugIsRefusedBeforeAnyRequest(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => throw new \LogicException('no request may be made'));
        $issues = new GitHubIssues($client, 'cycling/commons', 'k-1', 'https://example.test');

        $this->expectExceptionMessage('not_public');
        $issues->open($this->bug());
    }

    public function testUnconfiguredMeansNoButtonAndNoRequest(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => throw new \LogicException('no request may be made'));
        $issues = new GitHubIssues($client, '', '', 'https://example.test');
        self::assertFalse($issues->isConfigured());
        self::assertSame('', $issues->repo());

        $this->expectExceptionMessage('not_configured');
        $issues->open($this->bug());
    }

    public function testARefusalIsAnErrorNotANumber(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"message":"Validation Failed"}', ['http_code' => 422]));
        $issues = new GitHubIssues($client, 'cycling/commons', 'k-1', 'https://example.test');
        $report = $this->bug();
        $report->setPublic(true);
        $report->setPublicTitle('Public');

        $this->expectExceptionMessage('github_http_422');
        $issues->open($report);
    }

    private function bug(): BugReport
    {
        $report = new BugReport('A title', 'A body');
        $report->setSeverity(BugSeverity::Minor);
        $report->setArea(BugArea::Pages);

        return $report;
    }
}
