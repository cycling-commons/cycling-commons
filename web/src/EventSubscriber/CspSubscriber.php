<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\Csp\CspNonce;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Content-Security-Policy on every HTML response (frontend review 2026-07-12
 * W3): the app renders substantial builder-constructed innerHTML and injects
 * third-party scripts at runtime, so script execution is locked to self,
 * nonced inline blocks, and SRI-pinned unpkg — a stored-XSS payload that
 * slips past output escaping no longer executes.
 *
 * Source notes:
 *  - script-src: unpkg serves maplibre-gl + mapillary-js, both SRI-pinned at
 *    the include site; inline blocks carry the request nonce (csp_nonce()).
 *  - style-src: 'unsafe-inline' stays — the map/site JS sets many style
 *    attributes and MapLibre/mapillary inject inline styles; a style nonce
 *    cannot cover attribute styles. Script execution is the boundary here.
 *  - connect-src: basemap/satellite tiles, geocoders, routing, elevation,
 *    Mapillary APIs, self-hosted Umami. Mapillary image bytes come from
 *    Meta CDN hosts (*.fbcdn.net), fetched by mapillary-js.
 *  - img-src: Wikimedia Special:FilePath 302s to upload.wikimedia.org — CSP
 *    checks every hop, so both hosts are listed; data:/blob: for MapLibre
 *    sprites and generated icons.
 *  - worker-src blob: — MapLibre spawns its worker from a blob URL.
 *
 * @api Auto-registered event subscriber.
 */
final class CspSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly CspNonce $nonce)
    {
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $response = $event->getResponse();
        // Only documents need a policy — JSON/GPX/asset responses skip it.
        $type = $response->headers->get('Content-Type', '');
        if ('' !== $type && !str_contains($type, 'text/html')) {
            return;
        }

        $nonce = $this->nonce->value();
        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' https://unpkg.com",
            "style-src 'self' 'unsafe-inline' https://unpkg.com",
            "img-src 'self' data: blob: https://commons.wikimedia.org https://upload.wikimedia.org https://*.mapillary.com https://*.fbcdn.net",
            "font-src 'self'",
            'connect-src '.implode(' ', [
                "'self'",
                'https://tiles.openfreemap.org',
                'https://server.arcgisonline.com',
                'https://*.mapillary.com',
                'https://*.fbcdn.net',
                'https://nominatim.openstreetmap.org',
                'https://photon.komoot.io',
                'https://router.project-osrm.org',
                'https://api.open-meteo.com',
                'https://analytics.bikecoders.life',
            ]),
            'worker-src blob:',
            'child-src blob:',
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]));
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }
}
