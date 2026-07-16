<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Security;

use App\EventSubscriber\CspSubscriber;
use App\Security\Csp\CspNonce;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * coverage-provider.md §4: the browser range-reads the
 * PMTiles artifact straight from the bucket/CDN, so its origin must join
 * connect-src — but only when COVERAGE_CSP_HOST is configured; an empty
 * param leaves the policy byte-identical to before.
 */
final class CspCoverageHostTest extends TestCase
{
    private function cspWithCoverageHost(string $host): string
    {
        $response = new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/'),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
        (new CspSubscriber(new CspNonce(), $host))->onKernelResponse($event);

        return (string) $response->headers->get('Content-Security-Policy');
    }

    public function testConfiguredHostJoinsConnectSrc(): void
    {
        $csp = $this->cspWithCoverageHost('http://localhost:9100');
        self::assertSame(1, preg_match('/connect-src ([^;]+)/', $csp, $m));
        self::assertStringContainsString('http://localhost:9100', $m[1]);
    }

    public function testEmptyHostLeavesConnectSrcUntouched(): void
    {
        $csp = $this->cspWithCoverageHost('');
        self::assertSame(1, preg_match('/connect-src ([^;]+)/', $csp, $m));
        self::assertStringNotContainsString('9100', $m[1]);
        self::assertStringEndsWith('https://analytics.bikecoders.life', $m[1]);
    }
}
