<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * The stable, non-reversible "rider#abcd" pseudonym shown for a contributor in
 * curator-facing views (the moderation queue and an item's change history).
 * One definition so both surfaces render the same handle for the same user
 * (previously duplicated — review #54).
 *
 * @api Used by SubmissionQueue and ChangeHistoryView.
 */
final class RiderPseudonym
{
    public static function for(int|string $userId): string
    {
        return 'rider#'.substr(hash('crc32b', 'cc-sub-'.$userId), 0, 4);
    }
}
