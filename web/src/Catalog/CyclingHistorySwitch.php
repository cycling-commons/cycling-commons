<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;

/**
 * Whether the cycling history timeline is open: the page, its links in the
 * footer, on /pages and in the sitemap, and its note in What's new. The
 * system setting `cycling_history.live` (0 by default) switches it, so an
 * environment seeds its places first and opens the page after.
 *
 * @see docs/specs/cycling-history.md §5
 *
 * @api
 */
final readonly class CyclingHistorySwitch
{
    public function __construct(private SettingsProviderInterface $settings)
    {
    }

    public function isLive(): bool
    {
        return 1 === $this->settings->get(SettingsRegistry::CYCLING_HISTORY_LIVE);
    }
}
