<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

/**
 * Who to credit for one photo, resolved at render time
 * (docs/specs/photo-uploads.md §5d).
 *
 * A value object rather than a tuple because two further states arrive with the
 * phase-2 write API (docs/specs/public-api.md §8): an external contribution
 * renders "<shared name> · via app X", or "a rider, via app X" when the
 * partner app shared no name. Both set $viaApp and never a profile uuid — an
 * app-scoped author_ref has no Commons profile to link to. Neither is built
 * yet; $viaApp is always null in phase 1, and finding four states where the
 * spec lists six is correct rather than a stale spec.
 *
 * @api Returned by PhotoPageController::attribution().
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
