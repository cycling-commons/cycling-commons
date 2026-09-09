<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\Csp\CspNonce;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Content-Security-Policy on main HTML responses.
 *
 * @see docs/specs/security-architecture.md §2
 *
 * @api
 */
final class CspSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CspNonce $nonce,
        // env COVERAGE_CSP_HOST; empty omits it (docs/specs/coverage-provider.md §4).
        private readonly string $coverageCspHost = '',
        // env MEDIA_CSP_HOST; never admin-editable (docs/specs/photo-uploads.md §2).
        private readonly string $mediaCspHost = '',
    ) {
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $response = $event->getResponse();
        // JSON/GPX/assets skip CSP; nosniff on those is SecurityHeadersSubscriber.
        $type = $response->headers->get('Content-Type', '');
        if ('' !== $type && !str_contains($type, 'text/html')) {
            return;
        }

        $nonce = $this->nonce->value();

        // mapillary-js needs 'unsafe-eval' only on /map (docs/specs/security-architecture.md §2.4).
        $request = $event->getRequest();
        $isMap = 'map' === $request->attributes->get('_route')
            || str_ends_with($request->getPathInfo(), '/map');
        // The analytics host is named rather than nonce-carried. A nonce was
        // the old way in, and it forced the loader to copy its own nonce onto
        // the script it injects; naming the host is both simpler and what lets
        // a page carry no nonce at all, which is what a shared cache needs
        // (docs/specs/page-caching.md §3.2). It is the same host already
        // trusted in connect-src below.
        $scriptSrc = "script-src 'self' 'nonce-{$nonce}' https://analytics.bikecoders.life"
            .($isMap ? " 'unsafe-eval'" : '');

        $connectSrc = [
            "'self'",
            'https://tiles.openfreemap.org',
            // Esri World Imagery (docs/specs/map-and-search.md §2).
            'https://ibasemaps-api.arcgis.com',
            'https://*.mapillary.com',
            'https://*.fbcdn.net',
            'https://photon.komoot.io',
            'https://analytics.bikecoders.life',
        ];
        if ('' !== $this->coverageCspHost) {
            // Coverage PMTiles (docs/specs/coverage-provider.md §4).
            $connectSrc[] = $this->coverageCspHost;
        }

        $imgSrc = [
            "'self'",
            'data:',
            'blob:',
            'https://commons.wikimedia.org',
            'https://upload.wikimedia.org',
            'https://*.mapillary.com',
            'https://*.fbcdn.net',
        ];
        if ('' !== $this->mediaCspHost) {
            // Rider-photo proxy (docs/specs/photo-uploads.md §2).
            $imgSrc[] = $this->mediaCspHost;
        }

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            $scriptSrc,
            "style-src 'self' 'unsafe-inline'",
            'img-src '.implode(' ', $imgSrc),
            "font-src 'self'",
            'connect-src '.implode(' ', $connectSrc),
            // 'self' is MapLibre v6: it starts its tile worker from the
            // same-origin module URL (public/lib/.../maplibre-gl-worker.mjs)
            // rather than the blob: URL the v5 UMD bundle built. blob: stays
            // for mapillary-js, and is also v6's own fallback path when the
            // library is loaded cross-origin.
            "worker-src 'self' blob:",
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
