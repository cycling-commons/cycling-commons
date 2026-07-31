<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

/**
 * The result of processing one upload: three metadata-free WebP encodings plus
 * the two facts harvested from the original before its metadata was destroyed
 * (docs/specs/photo-uploads.md §1.3b). The coordinates here are the last raw
 * ones that will ever exist for this photo — intake turns them into a distance
 * and nulls them.
 *
 * @api Returned by PhotoProcessor.
 */
final readonly class ProcessedPhoto
{
    public function __construct(
        public string $orig,
        public string $lg,
        public string $sm,
        public int $width,
        public int $height,
        public ?\DateTimeImmutable $takenAt = null,
        public ?float $gpsLat = null,
        public ?float $gpsLng = null,
    ) {
    }
}
