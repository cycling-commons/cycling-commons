<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * Stable non-reversible `rider#abcd` handle for curator-facing views.
 *
 * @api
 */
final class RiderPseudonym
{
    public static function for(int|string $userId): string
    {
        return 'rider#'.substr(hash('crc32b', 'cc-sub-'.$userId), 0, 4);
    }
}
