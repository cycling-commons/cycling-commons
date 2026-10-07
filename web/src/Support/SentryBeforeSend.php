<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

use Sentry\Event;
use Sentry\EventHint;

/**
 * The Sentry bundle's `before_send` (config/packages/sentry.yaml): drops what
 * SentryNoise calls noise, and names the build on everything else.
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
    ) {
    }

    public function __invoke(Event $event, ?EventHint $hint = null): ?Event
    {
        if ($this->noise->isNoise($hint?->exception)) {
            return null;
        }

        return ($this->release)($event);
    }
}
