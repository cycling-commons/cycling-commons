<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Links;

use App\Links\WebsiteChecker;
use App\Links\WebsiteVerdict;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The fetch behind the verdict (docs/specs/website-liveness.md section 1):
 * one GET with our User-Agent and the limits, and the four outcomes.
 */
final class WebsiteCheckerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $lastOptions = [];
    private string $lastMethod = '';

    private function checker(MockResponse $response): WebsiteChecker
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->lastMethod = $method;
            $this->lastOptions = $options;

            return $response;
        });

        return new WebsiteChecker($http, new NullLogger());
    }

    public function testOneGetWithOurUserAgentAndTheLimits(): void
    {
        $checker = $this->checker(new MockResponse('<html><title>Camping</title></html>', ['http_code' => 200]));

        $result = $checker->check('https://example.test/');

        self::assertSame(WebsiteVerdict::LIVE, $result->verdict);
        self::assertSame(200, $result->status);
        self::assertSame('GET', $this->lastMethod);
        $headers = implode("\n", (array) ($this->lastOptions['headers'] ?? []));
        self::assertStringContainsString('CyclingCommons website check', $headers);
        self::assertSame(5, $this->lastOptions['max_redirects']);
        self::assertLessThanOrEqual(8.0, (float) $this->lastOptions['timeout']);
    }

    public function testAParkingPageIsParkedAndAnErrorIsDead(): void
    {
        self::assertSame(WebsiteVerdict::PARKED, $this->checker(new MockResponse('<title>ConnectYourDomain Error | Wix.com</title>', ['http_code' => 404]))->check('https://www.chevrouheid.com/')->verdict);
        self::assertSame(WebsiteVerdict::DEAD, $this->checker(new MockResponse('gone', ['http_code' => 410]))->check('https://example.test/')->verdict);
    }

    public function testATimeoutIsUnknownAndARefusedConnectionIsDead(): void
    {
        self::assertSame(WebsiteVerdict::UNKNOWN, $this->checker(new MockResponse('', ['error' => 'Operation timed out after 8001 milliseconds']))->check('https://slow.test/')->verdict);
        self::assertSame(WebsiteVerdict::DEAD, $this->checker(new MockResponse('', ['error' => 'Could not resolve host: nowhere.test']))->check('https://nowhere.test/')->verdict);
    }

    public function testAtMostConcurrencyRequestsAreOpenAtOnce(): void
    {
        // A provider ingest hands over thousands of hosts in one call; a socket
        // each ran a staging worker out of open files (EMFILE) on 2026-10-23.
        $open = 0;
        $peak = 0;
        $http = new MockHttpClient(static function () use (&$open, &$peak): MockResponse {
            $peak = max($peak, ++$open);

            return new MockResponse((static function () use (&$open): \Generator {
                yield '<html><title>Gîte</title></html>';
                --$open;
            })(), ['http_code' => 200]);
        });
        $urls = array_map(static fn (int $i): string => sprintf('https://site%d.test/', $i), range(1, 3 * WebsiteChecker::CONCURRENCY + 1));

        $verdicts = (new WebsiteChecker($http, new NullLogger()))->checkMany($urls);

        self::assertCount(\count($urls), $verdicts);
        self::assertSame(WebsiteVerdict::LIVE, $verdicts['https://site61.test/']->verdict);
        self::assertSame(WebsiteChecker::CONCURRENCY, $peak);
    }

    public function testProgressComesBeforeTheFirstBatchAndAfterEach(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('<title>Gîte</title>', ['http_code' => 200]));
        $urls = array_map(static fn (int $i): string => sprintf('https://site%d.test/', $i), range(1, 2 * WebsiteChecker::CONCURRENCY + 1));
        $urls[] = 'not an address';
        $calls = [];

        (new WebsiteChecker($http, new NullLogger()))->checkMany($urls, static function (int $done, int $total) use (&$calls): void {
            $calls[] = [$done, $total];
        });

        self::assertSame([[0, 41], [20, 41], [40, 41], [41, 41]], $calls, 'only fetched addresses count');
    }

    public function testAnAddressWithoutAHostIsNotFetched(): void
    {
        $checker = $this->checker(new MockResponse('never', ['http_code' => 200]));
        self::assertSame(WebsiteVerdict::DEAD, $checker->check('not an address')->verdict);
        self::assertSame('', $this->lastMethod);
    }
}
