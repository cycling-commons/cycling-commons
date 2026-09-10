<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

/**
 * Who to credit, resolved at render time.
 *
 * @see docs/specs/photo-uploads.md §5d
 *
 * @api
 */
final readonly class PhotoAttribution
{
    public function __construct(
        /** '' means anonymous. */
        public string $name,
        /** Rider profile uuid to link, when there is a profile to link. */
        public ?string $profileUuid = null,
        /** Partner app display name; always null until the write API lands. */
        public ?string $viaApp = null,
    ) {
    }
}
