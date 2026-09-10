<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\Message;

/**
 * Scan a quarantined upload; on clean, write public objects. Scalars only.
 *
 * @see docs/specs/media-storage-architecture.md §3
 *
 * @api
 */
final readonly class ScanAndReleaseUpload
{
    public function __construct(
        public string $mediaId,
        public ?float $pinLat = null,
        public ?float $pinLng = null,
    ) {
    }
}
