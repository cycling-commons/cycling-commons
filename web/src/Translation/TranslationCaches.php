<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Translation;

/**
 * The one call every writer makes after a commit: overlay maps, stale sets and
 * marker maps for all five locales. An English change touches every locale,
 * and a cache miss is one query, so nothing here is selective.
 *
 * @api
 */
final class TranslationCaches
{
    public function __construct(
        private readonly OverlayCatalogue $overlays,
        private readonly StaleIndex $stale,
        private readonly MarkerIndex $markers,
    ) {
    }

    public function invalidateAll(): void
    {
        foreach (TranslationLimits::OVERLAY_LOCALES as $locale) {
            $this->overlays->invalidate($locale);
            $this->stale->invalidate($locale);
            $this->markers->invalidate($locale);
        }
    }
}
