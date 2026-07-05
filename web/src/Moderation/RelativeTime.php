<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

/**
 * Human age of a submission ("2h ago") for the queue and the map drawer.
 *
 * @api Used by SubmissionQueue.
 */
final class RelativeTime
{
    public static function ago(\DateTimeImmutable $then, \DateTimeImmutable $now): string
    {
        $s = max(0, $now->getTimestamp() - $then->getTimestamp());

        return match (true) {
            $s < 60 => 'just now',
            $s < 3600 => intdiv($s, 60).'m ago',
            $s < 86400 => intdiv($s, 3600).'h ago',
            default => intdiv($s, 86400).'d ago',
        };
    }
}
