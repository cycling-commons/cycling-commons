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

    public function testAnAddressWithoutAHostIsNotFetched(): void
    {
        $checker = $this->checker(new MockResponse('never', ['http_code' => 200]));
        self::assertSame(WebsiteVerdict::DEAD, $checker->check('not an address')->verdict);
        self::assertSame('', $this->lastMethod);
    }
}
