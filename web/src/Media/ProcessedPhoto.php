<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

/**
 * Three metadata-free WebPs plus harvested takenAt/GPS (GPS is then destroyed).
 *
 * @see docs/specs/photo-uploads.md §1
 *
 * @api
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
