<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Translation\MarkerCodec;
use App\Translation\TranslateMode;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The first of the two nets that stop a translate-mode mark leaving the
 * server anywhere except inside an HTML page (translations.md §4.1).
 *
 * Marks exist to be read by `assets/js/translate-mode.js` on a rendered page,
 * so every response whose Content-Type is NOT `text/html` (JSON, `boot.js`,
 * GPX, the sitemap) has them stripped before it reaches the browser. Runs at
 * a very late priority: after everything else that writes or rewrites
 * content, so nothing downstream of this can put a mark back.
 *
 * `TranslateMode::isActive()` is the first thing checked, before any header
 * or body work: on an ordinary request with the mode off (almost all of
 * them) the cost is one attribute read on the main request and nothing else.
 *
 * @api
 */
final readonly class MarkStripResponseSubscriber implements EventSubscriberInterface
{
    public function __construct(private TranslateMode $mode)
    {
    }

    /** @return array<string, array{string, int}> */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        // Late, after everything that writes content.
        return [KernelEvents::RESPONSE => ['onResponse', -1024]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->mode->isActive()) {
            return;
        }

        $response = $event->getResponse();
        // Neither has a body this can scan: `getContent()` on both always
        // answers `false`, and `BinaryFileResponse::setContent()` THROWS for
        // any non-null value, so reaching that call on a file download would
        // break the download outright rather than merely skip it. The
        // `!\is_string($content)` check below would also catch `false`, so
        // this is belt-and-suspenders: explicit, and it skips the
        // Content-Type work for two response kinds that can never carry a
        // mark anyway.
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return;
        }

        $type = (string) $response->headers->get('Content-Type', '');
        if (str_starts_with($type, 'text/html')) {
            // The one place a mark is meant to be read.
            return;
        }

        $content = $response->getContent();
        if (!\is_string($content)) {
            return;
        }

        $stripped = MarkerCodec::stripAllForms($content);
        if ($stripped === $content) {
            return;
        }

        $response->setContent($stripped);
        // The body just changed, so any ETag computed from the old one is now
        // wrong: leaving it would let a client, or a shared cache keyed on
        // it, treat the marked and the stripped body as the same response.
        if ($response->headers->has('ETag')) {
            $response->setEtag(md5($stripped));
        }
    }
}
