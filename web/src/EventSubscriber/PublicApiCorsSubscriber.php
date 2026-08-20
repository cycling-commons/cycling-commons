<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CORS for the public API (public-api.md §2.2 PoC): every /v1 response
 * (200, 304, 400, and 429 alike, or a browser consumer could not even read
 * the error) carries a wildcard allow-origin. GET-only with no custom
 * request headers means browsers send these as CORS "simple requests" and
 * never preflight, so no nelmio dependency is warranted yet; the OPTIONS
 * short-circuit below is defensive for hand-rolled clients that preflight
 * anyway. Swap for nelmio/cors-bundle when keys add an Authorization header
 * (that is the moment preflights become real).
 *
 * @api Auto-registered event subscriber.
 */
final class PublicApiCorsSubscriber implements EventSubscriberInterface
{
    private const array CORS_HEADERS = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET',
        'Access-Control-Max-Age' => '3600',
    ];

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            // Priority 33: one above the router (32), so an OPTIONS probe is
            // answered before routing 405s it (the routes declare GET only).
            KernelEvents::REQUEST => ['onKernelRequest', 33],
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethod('OPTIONS') || !str_starts_with($request->getPathInfo(), '/v1/')) {
            return;
        }
        $event->setResponse(new Response('', 204, self::CORS_HEADERS + ['Allow' => 'GET']));
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !str_starts_with($event->getRequest()->getPathInfo(), '/v1/')) {
            return;
        }
        foreach (self::CORS_HEADERS as $name => $value) {
            $event->getResponse()->headers->set($name, $value);
        }
    }
}
