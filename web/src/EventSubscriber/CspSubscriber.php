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
 *    Meta CDN hosts (*.fbcdn.net), fetched by mapillary-js. Coverage PMTiles
 *    host joins via COVERAGE_CSP_HOST (coverage-provider.md §4).
 *  - img-src: Wikimedia Special:FilePath 302s to upload.wikimedia.org — CSP
 *    checks every hop, so both hosts are listed; data:/blob: for MapLibre
 *    sprites and generated icons.
 *  - worker-src blob: — MapLibre spawns its worker from a blob URL.
 *
 * @api Auto-registered event subscriber.
 */
final class CspSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CspNonce $nonce,
        // Browser-facing origin of the coverage PMTiles artifact (env
        // COVERAGE_CSP_HOST via config/packages/coverage.yaml). A dedicated
        // param, not a response-time parse of the manifest URL: the manifest
        // is fetched server-side and may live on a different host than the
        // tile bytes the browser reads (dev: minio:9000 vs localhost:9100).
        // Empty string = no coverage host in the policy.
        private readonly string $coverageCspHost = '',
    ) {
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

        // The Mapillary street-level viewer (mapillary-js) compiles MapLibre-style
        // filter expressions with new Function() (its FilterCreator) when opened,
        // which requires 'unsafe-eval'. Scope that relaxation to the /map page only
        // — every other response keeps the strict, eval-free policy. (MapLibre GL
        // itself is CSP-safe; only mapillary-js needs this.)
        $request = $event->getRequest();
        $isMap = 'map' === $request->attributes->get('_route')
            || str_ends_with($request->getPathInfo(), '/map');
        $scriptSrc = "script-src 'self' 'nonce-{$nonce}' https://unpkg.com"
            .($isMap ? " 'unsafe-eval'" : '');

        $connectSrc = [
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
        ];
        if ('' !== $this->coverageCspHost) {
            // Coverage PMTiles byte-range reads straight off the bucket/CDN
            // (coverage-provider.md §4).
            $connectSrc[] = $this->coverageCspHost;
        }

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            $scriptSrc,
            "style-src 'self' 'unsafe-inline' https://unpkg.com",
            "img-src 'self' data: blob: https://commons.wikimedia.org https://upload.wikimedia.org https://*.mapillary.com https://*.fbcdn.net",
            "font-src 'self'",
            'connect-src '.implode(' ', $connectSrc),
            'worker-src blob:',
            'child-src blob:',
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]));
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }
}
