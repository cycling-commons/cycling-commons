<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * A rider's stored preference for which view mode the map opens in
 * (map-and-search.md §4.2; pre-spec:
 * docs/plans/github-issues-backlog.md #8).
 *
 * `Auto` is the default and means "let the region decide": the map opens in
 * Curated when the active region has earned a curated default, and in
 * Everything otherwise. The other two are the rider overriding that, and they
 * WIN over the region — a rider who has said "always show me everything" means
 * it in a well-curated region too.
 *
 * Stored on the profile rather than in localStorage on purpose (owner decision,
 * 2026-07-24): people share devices, and a device-scoped default would leak one
 * person's choice to the next. Anonymous visitors have no profile, so their
 * manual choice stays in localStorage and is session-scoped by nature.
 *
 * @api User-preference vocabulary; consumed by SettingsType, User and MapController.
 */
enum MapViewMode: string
{
    case Auto = 'auto';
    case Everything = 'everything';
    case Confirmed = 'confirmed';
    case Curated = 'curated';

    /**
     * The token the map's own toggle uses (`#mode button[data-m]`). Deliberately
     * NOT the enum value: the toggle has said `all`, not `everything`, since the
     * HTML demo, and renaming a live DOM contract to match a new enum would be
     * the tail wagging the dog. `Auto` has no token — it resolves per region at
     * load time, client-side.
     *
     * `Confirmed` is the middle rung (owner 2026-08-12): everything a human has
     * vouched for, whether by standing there and confirming it or by a curator
     * verifying it, which makes it a true superset of Curated rather than a
     * third unrelated filter.
     */
    public function clientToken(): ?string
    {
        return match ($this) {
            self::Auto => null,
            self::Everything => 'all',
            self::Confirmed => 'confirmed',
            self::Curated => 'curated',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $m): string => $m->value, self::cases());
    }
}
