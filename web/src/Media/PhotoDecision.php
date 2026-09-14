<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

/**
 * What PhotoValidator decides about one photo on one place.
 *
 * - `Show`: link it and show it;
 * - `Hide`: link it but do not show it. Only a rider photo on a scenic view
 *   with no usable distance to the pin gets this: the entry stays on the item
 *   so a curator can confirm "Taken here" (photo-uploads.md §5g);
 * - `Refuse`: do not link it, and do not show it where it is already linked.
 *
 * @see docs/specs/photo-uploads.md §5h
 *
 * @api
 */
enum PhotoDecision: string
{
    case Show = 'show';
    case Hide = 'hide';
    case Refuse = 'refuse';
}
