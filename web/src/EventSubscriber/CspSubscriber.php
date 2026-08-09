<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\Csp\CspNonce;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sets the Content-Security-Policy header on every main HTML response.
 * Script execution is locked to same-origin, nonced inline blocks, and
 * vendored same-origin scripts, so a stored-XSS payload that slips past output
 * escaping still does not execute. The directive table and the rationale for
 * each source list live in the linked spec, not here.
 *
 * @see docs/specs/security-architecture.md §2
 *
 * @api Auto-registered event subscriber.
 */
final class CspSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CspNonce $nonce,
        // Browser-facing origin of the coverage PMTiles artifact (env
        // COVERAGE_CSP_HOST). A separate param, not a parse of the manifest
        // URL at response time: the manifest is fetched server-side and can
        // live on a different host than the tile bytes the browser reads
        // (dev: minio:9000 vs localhost:9100). Empty string means no coverage
        // host is added to the policy.
        private readonly string $coverageCspHost = '',
        // Browser-facing origin of the rider-photo proxy (env MEDIA_CSP_HOST).
        // Env-backed and never admin-editable: a writable CSP host would be an
        // XSS-relaxation surface (docs/specs/photo-uploads.md §2).
        private readonly string $mediaCspHost = '',
    ) {
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $response = $event->getResponse();
        // Only documents need a policy. JSON/GPX/asset responses skip it.
        $type = $response->headers->get('Content-Type', '');
        if ('' !== $type && !str_contains($type, 'text/html')) {
            return;
        }

        $nonce = $this->nonce->value();

        // The Mapillary street-level viewer (mapillary-js) compiles MapLibre-style
        // filter expressions with new Function() (its FilterCreator) when opened,
        // which requires 'unsafe-eval'. Scope that relaxation to the /map page
        // only; every other response keeps the strict, eval-free policy.
        // (MapLibre GL itself is CSP-safe; only mapillary-js needs this.)
        // Full rationale and residual-risk assessment:
        // docs/specs/security-architecture.md §2.4.
        $request = $event->getRequest();
        $isMap = 'map' === $request->attributes->get('_route')
            || str_ends_with($request->getPathInfo(), '/map');
        $scriptSrc = "script-src 'self' 'nonce-{$nonce}'"
            .($isMap ? " 'unsafe-eval'" : '');

        $connectSrc = [
            "'self'",
            'https://tiles.openfreemap.org',
            // Esri World Imagery, keyed since 2026-08-09. The old keyless
            // server.arcgisonline.com host is gone with it — see
            // docs/specs/Dated/2026-08-09-esri-imagery-terms.md.
            'https://ibasemaps-api.arcgis.com',
            'https://*.mapillary.com',
            'https://*.fbcdn.net',
            'https://photon.komoot.io',
            'https://analytics.bikecoders.life',
        ];
        if ('' !== $this->coverageCspHost) {
            // Coverage PMTiles byte-range reads straight off the bucket/CDN
            // (docs/specs/coverage-provider.md §4).
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
            // Rider photos are fetched from the media proxy host
            // (docs/specs/photo-uploads.md §2).
            $imgSrc[] = $this->mediaCspHost;
        }

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            $scriptSrc,
            "style-src 'self' 'unsafe-inline'",
            'img-src '.implode(' ', $imgSrc),
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
