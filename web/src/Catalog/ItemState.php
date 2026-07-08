<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Lifecycle state of a catalog row (edit-items funnel). Imports always enter
 * as Unverified; upsert-updates never touch state. @api Catalog domain enum.
 */
enum ItemState: string
{
    case Submitted = 'submitted';
    case Unverified = 'unverified';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Retired = 'retired';

    /** Spec §8: the only lifecycle states ever served publicly. */
    public const array SERVED = [self::Unverified, self::Verified];

    /** SQL tuple literal for interpolation into raw DBAL queries. */
    public static function servedSqlTuple(): string
    {
        return "('".implode("', '", array_map(static fn (self $s): string => $s->value, self::SERVED))."')";
    }
}
