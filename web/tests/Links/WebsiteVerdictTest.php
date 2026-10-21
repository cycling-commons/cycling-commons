<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Links;

use App\Links\WebsiteVerdict;
use PHPUnit\Framework\TestCase;

/**
 * One address, one of four words (docs/specs/website-liveness.md section 1).
 */
final class WebsiteVerdictTest extends TestCase
{
    public function testAPageThatAnswersIsLive(): void
    {
        self::assertSame(WebsiteVerdict::LIVE, WebsiteVerdict::of(200, '<html><title>Gîte du Moulin</title><body>Welcome</body></html>')->verdict);
        self::assertSame(WebsiteVerdict::LIVE, WebsiteVerdict::of(301, '')->verdict);
    }

    public function testAHostingProvidersConnectYourDomainPageIsParkedWhateverTheStatus(): void
    {
        $v = WebsiteVerdict::of(404, '<html><head><title>ConnectYourDomain Error | Wix.com</title></head><body>Domain Error</body></html>');
        self::assertSame(WebsiteVerdict::PARKED, $v->verdict);
        self::assertSame('connectyourdomain', $v->reason);
    }

    public function testEverySignatureParks(): void
    {
        foreach (WebsiteVerdict::PARKED_SIGNATURES as $sig) {
            self::assertSame(WebsiteVerdict::PARKED, WebsiteVerdict::of(200, "<html><body>Hello. This Domain Is For Sale. $sig </body></html>")->verdict, $sig);
        }
        self::assertSame(WebsiteVerdict::PARKED, WebsiteVerdict::of(200, '<title>THIS DOMAIN IS PARKED</title>')->verdict, 'matched regardless of case');
    }

    public function testAnErrorStatusWithoutASignatureIsDead(): void
    {
        self::assertSame(WebsiteVerdict::DEAD, WebsiteVerdict::of(404, '<html>Not found</html>')->verdict);
        self::assertSame(WebsiteVerdict::DEAD, WebsiteVerdict::of(503, '')->verdict);
    }

    public function testAServerThatTurnsAScriptAwayIsUnknownNotDead(): void
    {
        foreach ([401, 403, 429] as $status) {
            self::assertSame(WebsiteVerdict::UNKNOWN, WebsiteVerdict::of($status, '<html>Forbidden</html>')->verdict, 'http '.$status);
        }
        self::assertSame(WebsiteVerdict::PARKED, WebsiteVerdict::of(403, '<title>Buy this domain</title>')->verdict, 'a parking signature still parks');
    }

    public function testTransportFailuresSplitIntoDeadAndUnknown(): void
    {
        self::assertSame(WebsiteVerdict::DEAD, WebsiteVerdict::failed('Could not resolve host: example.test')->verdict);
        self::assertSame(WebsiteVerdict::DEAD, WebsiteVerdict::failed('Connection refused')->verdict);
        self::assertSame(WebsiteVerdict::DEAD, WebsiteVerdict::failed('SSL certificate problem: certificate has expired')->verdict);
        self::assertSame(WebsiteVerdict::UNKNOWN, WebsiteVerdict::failed('Operation timed out after 8000 milliseconds')->verdict);
        self::assertSame(WebsiteVerdict::UNKNOWN, WebsiteVerdict::failed('something else entirely')->verdict);
        self::assertSame(WebsiteVerdict::DEAD, WebsiteVerdict::failed('Host "127.0.0.1" is blocked for "http://127.0.0.1/".')->verdict, 'our own network is never a website');
    }

    public function testEachVerdictHasItsPeriod(): void
    {
        self::assertSame(30, WebsiteVerdict::daysUntilRecheck(WebsiteVerdict::LIVE));
        self::assertSame(14, WebsiteVerdict::daysUntilRecheck(WebsiteVerdict::PARKED));
        self::assertSame(14, WebsiteVerdict::daysUntilRecheck(WebsiteVerdict::DEAD));
        self::assertSame(3, WebsiteVerdict::daysUntilRecheck(WebsiteVerdict::UNKNOWN));
    }

    public function testTheKeyIsTheLowerCasedHostWithoutThePort(): void
    {
        self::assertSame('www.chevrouheid.com', WebsiteVerdict::hostOf('https://www.Chevrouheid.com/'));
        self::assertSame('example.test', WebsiteVerdict::hostOf('http://example.test:8080/path?q=1'));
        self::assertNull(WebsiteVerdict::hostOf('not an address'));
        self::assertNull(WebsiteVerdict::hostOf('mailto:someone@example.test'));
    }
}
