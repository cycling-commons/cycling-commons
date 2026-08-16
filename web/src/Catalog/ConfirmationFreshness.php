<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;

/**
 * How old a place's last confirmation is, in the three words the map uses.
 *
 * An item confirmed half a year ago is not the same claim as one confirmed
 * last week, and until now the map drew them identically (owner 2026-08-12).
 * This is the state behind that difference.
 *
 * **THREE RULES DECIDE WHO GETS A STATE AT ALL, and they exist to survive one
 * failure**: six months after launch most of the map is orange, and an orange
 * that means "everything" means nothing. Each rule removes a class of item that
 * would be orange for a reason nobody can act on.
 *
 *  1. **Never confirmed is not stale, it is UNVERIFIED.** An item nobody has
 *     ever stood next to has a different state with its own signal already
 *     (`v` absent in the payload; `stateUnverified` in the drawer). Ageing
 *     something that was never fresh is noise, and it would paint every
 *     harvested OSM row on the map orange on day one.
 *  2. **Only letters whose confirmations GO OFF** ({@see
 *     ItemType::confirmationAges()}). A tap breaks and a shop shuts; a
 *     viewpoint does not stop being a view.
 *  3. **One confirmation resets the clock.** The point is a nudge, not a
 *     chore, so a rider who checks a tap buys it another full window rather
 *     than adding to a tally.
 *
 * The window itself is `map.confirmation_stale_months` on /admin/system-config,
 * beside the other editorial dials, and NOT a constant somebody has to
 * redeploy to change - six months is a guess about how fast the built world
 * changes, and a guess belongs where it can be revised.
 *
 * The middle band is half the window: a six-month dial reads fresh for three
 * months, ageing for three, then stale. Ageing exists so the nudge arrives
 * before the claim is worthless, and halving is the one split that needs no
 * second dial to explain it.
 *
 * @api Autowired into CatalogProvider (the map payload) and ProfileController
 *      (the rider's "worth a look near you" list) - `@api` tells Psalm the
 *      constructor is live, not dead code.
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
     * The state for one item, or null when this item takes no state at all.
     *
     * Null is not "fresh" and not an error: it is the answer for the two thirds
     * of the map that rules 1 and 2 exclude, and the payload leaves the key off
     * entirely so those items stay byte-identical to what they were before this
     * existed.
     *
     * @param \DateTimeImmutable|null $lastConfirmed the newest NON-`form`
     *                                               confirmation; a submitter
     *                                               answering their own form is
     *                                               not somebody having checked
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

        // intdiv, so an odd window rounds the ageing band DOWN and the fresh
        // band keeps the spare month. A nudge that arrives slightly early is
        // the harmless direction of that rounding.
        $ageing = $lastConfirmed->modify(sprintf('+%d months', max(1, intdiv($full, 2))));

        return $now >= $ageing ? self::AGEING : self::FRESH;
    }

    /**
     * The SQL boundary a query can use to select stale items directly, so the
     * "stale places near you" list is one indexed comparison rather than a
     * state computed per row in PHP.
     */
    public function staleBefore(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(sprintf('-%d months', $this->staleMonths()));
    }
}
