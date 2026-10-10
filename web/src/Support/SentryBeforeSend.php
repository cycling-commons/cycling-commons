<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The Sentry bundle's `before_send` (config/packages/sentry.yaml): drops what
 * SentryNoise calls noise, scrubs the request by the route that handled it
 * ({@see SentryRequestScrubber}), and names the build on everything else.
 *
 * @see docs/specs/operations.md §1
 *
 * @api
 */
final readonly class SentryBeforeSend
{
    public function __construct(
        private SentryNoise $noise,
        private SentryRelease $release,
        private RequestStack $requests,
    ) {
    }

    public function __invoke(Event $event, ?EventHint $hint = null): ?Event
    {
        if ($this->noise->isNoise($hint?->exception)) {
            return null;
        }
        $request = $event->getRequest();
        if ([] !== $request) {
            $route = $this->requests->getMainRequest()?->attributes->get('_route');
            $event->setRequest(SentryRequestScrubber::scrub($request, \is_string($route) ? $route : null));
        }

        return ($this->release)($event);
    }
}
