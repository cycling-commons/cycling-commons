<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Account;

/**
 * How many rows a rider wants on one page of any list
 * (docs/specs/account-and-auth.md §9).
 *
 * `Auto` is the default and means "whatever each list was built for". That is
 * deliberately NOT one number: the messages dashboard shows 20 because a
 * message is a card, a moderation desk shows 25 because a queue item is a row
 * of work, the contributors wall shows 60 because a wall row is one line and
 * somebody scanning for a name would rather scroll than click. A single global
 * default would have to be wrong for two of those three.
 *
 * The explicit options override every list at once, which is the point: a
 * rider who says "50" is telling us something about their screen and their
 * patience, not about our page design.
 *
 * @api User-preference vocabulary; consumed by SettingsType, User and App\Pagination\PageSize.
 */
enum RowsPerPage: string
{
    /** Each list keeps the size it was designed around. */
    case Auto = 'auto';
    case N25 = '25';
    case N50 = '50';
    case N100 = '100';

    /** The row count, or null for Auto — where the caller's own default wins. */
    public function rows(): ?int
    {
        return match ($this) {
            self::Auto => null,
            self::N25 => 25,
            self::N50 => 50,
            self::N100 => 100,
        };
    }

    /** The translation key for this option's label in the settings dropdown. */
    public function labelKey(): string
    {
        return match ($this) {
            self::Auto => 'form.rows_per_page_auto',
            self::N25 => 'form.rows_per_page_25',
            self::N50 => 'form.rows_per_page_50',
            self::N100 => 'form.rows_per_page_100',
        };
    }
}
