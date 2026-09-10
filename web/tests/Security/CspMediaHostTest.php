<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Security;

use App\EventSubscriber\CspSubscriber;
use App\Security\Csp\CspNonce;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The media proxy host joins img-src only when it is configured, exactly the
 * way the coverage host joins connect-src (docs/specs/photo-uploads.md §2).
 * Instantiated directly rather than through the container: the point of the
 * test is the string assembly for BOTH configured and unconfigured hosts, and a
 * container parameter cannot be toggled inside one boot.
 */
final class CspMediaHostTest extends TestCase
{
    private function policyFor(string $mediaCspHost): string
    {
        $subscriber = new CspSubscriber(new CspNonce(), '', $mediaCspHost);
        $response = new Response('<html></html>', 200, ['Content-Type' => 'text/html']);
        $event = new ResponseEvent(
            $this->createStub(KernelInterface::class),
            Request::create('/map'),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
        $subscriber->onKernelResponse($event);

        return (string) $response->headers->get('Content-Security-Policy');
    }

    public function testConfiguredMediaHostJoinsImgSrc(): void
    {
        $policy = $this->policyFor('https://media.example');

        self::assertMatchesRegularExpression(
            '#img-src [^;]*https://media\.example#',
            $policy,
            'the media proxy host must be an img-src source',
        );
    }

    public function testUnconfiguredMediaHostAddsNothing(): void
    {
        $policy = $this->policyFor('');

        self::assertStringNotContainsString('media.example', $policy);
        self::assertStringContainsString("img-src 'self' data: blob:", $policy);
    }

    public function testTheMediaHostNeverLeaksIntoAnotherDirective(): void
    {
        $policy = $this->policyFor('https://media.example');

        self::assertStringNotContainsString(
            'connect-src \'self\' https://media.example',
            $policy,
            'the media host belongs to img-src only; connect-src is the coverage host\'s directive',
        );
        self::assertStringContainsString("default-src 'self';", $policy);
    }
}
