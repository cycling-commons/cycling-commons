<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

use App\Catalog\ItemType;

/**
 * The place a photo is linked to: its catalogue letter and its pin.
 *
 * The pin is the point an item's photos are measured against,
 * `ST_PointOnSurface(item.geom)`, or a coverage point's own position. Either
 * half may be unknown; a town card has no letter at all (unplaced()).
 *
 * @see docs/specs/photo-uploads.md §5h
 *
 * @api
 */
final readonly class PhotoPlace
{
    public function __construct(
        public ?string $letter,
        public ?float $lat,
        public ?float $lng,
    ) {
    }

    /** A photo shown with no catalogue place behind it, such as a town card's. */
    public static function unplaced(): self
    {
        return new self(null, null, null);
    }

    /** From loosely typed row values, as the database hands them over. */
    public static function of(mixed $letter, mixed $lat, mixed $lng): self
    {
        return new self(
            \is_string($letter) && '' !== trim($letter) ? trim($letter) : null,
            is_numeric($lat) ? (float) $lat : null,
            is_numeric($lng) ? (float) $lng : null,
        );
    }

    /** A scenic view (letter P) promises the view from its pin. */
    public function isScenicView(): bool
    {
        return ItemType::ScenicViews->letter() === $this->letter;
    }
}
