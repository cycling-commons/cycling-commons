<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media\Message;

/**
 * The async tier's first message (media plan tasks 2-4): scan a quarantined
 * upload and, on a clean verdict, physically release its derivatives to the
 * public bucket.
 *
 * Scalars only, and the media id as its RFC 4122 string: a message is a row
 * in a Redis stream that outlives deploys, so it must never carry an entity
 * or anything whose serialization couples it to today's code.
 *
 * The pin coordinates ride along TRANSIENTLY (never persisted): continent
 * resolution is pin -> EXIF GPS -> default, and the EXIF half only exists
 * after the worker's decode, so the worker needs the pin to finish the
 * question the endpoint could only start.
 *
 * @api Dispatched by the media upload endpoint once the flow goes async
 *      (plan task 2); handled by ScanAndReleaseUploadHandler.
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
