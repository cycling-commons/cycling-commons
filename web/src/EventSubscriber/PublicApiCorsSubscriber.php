<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CORS for `/v1` responses, including errors. GET-only; no preflight in browsers.
 *
 * @see docs/specs/public-api.md §2.2
 *
 * @api
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
            // Priority 33: one above the router (32), so OPTIONS is not 405'd (GET-only routes).
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
