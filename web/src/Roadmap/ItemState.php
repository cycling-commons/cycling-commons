<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Roadmap;

/**
 * Whether a roadmap item is still on the roadmap. Only `Live` is listed; a
 * shipped or removed item stays as a row, so a promoted proposal keeps
 * pointing at something.
 *
 * @see docs/specs/roadmap-and-changelog.md §8
 *
 * @api
 */
enum ItemState: string
{
    case Live = 'live';
    case Shipped = 'shipped';
    case Removed = 'removed';
}
