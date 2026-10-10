<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\SentryBeforeSend;
use Sentry\Event;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The hook GlitchTip events pass through scrubs the request by the route that
 * handled it (App\Support\SentryRequestScrubber).
 */
final class SentryBeforeSendTest extends KernelTestCase
{
    public function testTheBodyOfAnOrdinaryFormTravelsWithItsSecretsMasked(): void
    {
        $event = $this->send('moderate_decide', ['decision' => 'reject', 'note' => 'Not here', '_token' => 'abc']);

        self::assertSame(['decision' => 'reject', 'note' => 'Not here', '_token' => '[Filtered]'], $event?->getRequest()['data'] ?? null);
    }

    public function testThePersonalFormsSendNoBody(): void
    {
        $event = $this->send('login.en', ['email' => 'x@example.test', '_password' => 'secret']);

        self::assertArrayNotHasKey('data', $event?->getRequest() ?? []);
    }

    /** @param array<string, string> $data */
    private function send(string $route, array $data): ?Event
    {
        self::bootKernel();
        $request = Request::create('/somewhere', 'POST', $data);
        $request->attributes->set('_route', $route);
        static::getContainer()->get(RequestStack::class)->push($request);

        $event = Event::createEvent();
        $event->setRequest(['url' => 'https://cyclingcommons.org/somewhere', 'method' => 'POST', 'data' => $data]);
        $hook = static::getContainer()->get(SentryBeforeSend::class);
        self::assertInstanceOf(SentryBeforeSend::class, $hook);

        return $hook($event);
    }
}
