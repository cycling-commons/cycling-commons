<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;

/**
 * Fresh / ageing / stale for a confirmation. Never-confirmed is unverified, not stale. Null means this item takes no freshness key (byte-stable).
 *
 * @see docs/specs/moderation-and-contribution.md §10.1a
 *
 * @api
 */
final class ConfirmationFreshness
{
    public const string FRESH = 'fresh';
    public const string AGEING = 'ageing';
    public const string STALE = 'stale';

    public function __construct(private readonly SettingsProviderInterface $settings)
    {
    }

    /** Full window in months, after which a confirmation reads as stale. */
    public function staleMonths(): int
    {
        return $this->settings->get(SettingsRegistry::MAP_CONFIRMATION_STALE_MONTHS);
    }

    /**
     * Null is not "fresh": rules 1–2 exclude the item and the payload omits the key.
     *
     * @param \DateTimeImmutable|null $lastConfirmed newest non-`form` confirmation
     */
    public function state(ItemType $type, ?\DateTimeImmutable $lastConfirmed, \DateTimeImmutable $now): ?string
    {
        if (null === $lastConfirmed || !$type->confirmationAges()) {
            return null;
        }
        $full = $this->staleMonths();
        $stale = $lastConfirmed->modify(sprintf('+%d months', $full));
        if ($now >= $stale) {
            return self::STALE;
        }

        // intdiv: odd window rounds the ageing band down so the nudge arrives early, not late.
        $ageing = $lastConfirmed->modify(sprintf('+%d months', max(1, intdiv($full, 2))));

        return $now >= $ageing ? self::AGEING : self::FRESH;
    }

    /**
     * SQL boundary for selecting stale items in one indexed comparison.
     */
    public function staleBefore(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(sprintf('-%d months', $this->staleMonths()));
    }
}
