<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * Lifecycle state of a catalog row. Imports enter Unverified; upsert-updates never touch state.
 *
 * @see docs/specs/catalog-data-model.md §4
 *
 * @api
 */
enum ItemState: string
{
    case Submitted = 'submitted';
    case Unverified = 'unverified';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Retired = 'retired';

    /** docs/specs/catalog-data-model.md §4: the only lifecycle states ever served publicly. */
    public const array SERVED = [self::Unverified, self::Verified];

    /** SQL tuple literal for interpolation into raw DBAL queries. */
    public static function servedSqlTuple(): string
    {
        return "('".implode("', '", array_map(static fn (self $s): string => $s->value, self::SERVED))."')";
    }
}
