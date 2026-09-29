<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

use App\Service\BuildVersion;
use Sentry\Event;

/**
 * Names the running build on every GlitchTip event: `cyclingcommons@<tag>+<sha12>`.
 *
 * From the BuildVersion stamp, so GlitchTip, the footer and /humans.txt cannot
 * name different code. At send time rather than in sentry.yaml, because the
 * stamp is read from the release directory (REVISION, VERSION) at runtime.
 * Before this every event said `1.0.0+no-version-set`, and nobody could tell
 * which deploy brought an error in or took it out.
 *
 * @see docs/specs/operations.md §1
 *
 * @api
 */
final class SentryRelease
{
    private const string NAME = 'cyclingcommons';

    public function __construct(
        private readonly BuildVersion $build,
    ) {
    }

    public function __invoke(Event $event): Event
    {
        $event->setRelease($this->release());

        return $event;
    }

    public function release(): string
    {
        $stamp = $this->build->stamp();
        $short = substr($stamp['commit'], 0, 12);

        // With no tag the number already is the short commit; say it once.
        if ('' === $short || $stamp['number'] === $short) {
            return self::NAME.'@'.$stamp['number'];
        }

        return self::NAME.'@'.$stamp['number'].'+'.$short;
    }
}
