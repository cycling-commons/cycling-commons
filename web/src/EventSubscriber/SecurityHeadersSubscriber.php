<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Baseline browser-hardening headers on EVERY main response (review
 * 2026-08-16 finding 4) — deliberately including the JSON and GPX responses
 * that CspSubscriber skips: `nosniff` does its real work exactly there, where
 * a browser would otherwise content-sniff a served file into something
 * executable.
 *
 * - `X-Content-Type-Options: nosniff` — the declared Content-Type is the
 *   contract; the browser must not guess a better one.
 * - `Referrer-Policy: strict-origin-when-cross-origin` — a map URL carries
 *   the region and feature someone is looking at; external links must learn
 *   the origin at most, never the path.
 * - `Permissions-Policy` — camera, microphone and geolocation all locked:
 *   nothing in the app uses them (the vendored map library ships a geolocate
 *   control, but no page instantiates it). Loosen the one you need the day
 *   that changes, in this file and its test together.
 *
 * HSTS is deliberately NOT set here: TLS terminates in front of the app, and
 * the header belongs on the nginx frontends
 * (docs/plans/handoffs/2026-08-17-nginx-headers-devops.md).
 *
 * @api Auto-registered event subscriber.
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }
}
