<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

/**
 * An image the server drew again from a pixel buffer, ready to keep.
 *
 * What {@see ScreenshotStore::render()} hands back: the bytes this server's
 * Imagick wrote, never the file that was uploaded. Both the bug report and the
 * curator room store one of these; each wraps it in its own entity.
 *
 * @api
 */
final readonly class StoredImage
{
    public function __construct(
        public string $mimeType,
        public string $bytes,
        public int $width,
        public int $height,
    ) {
    }
}
