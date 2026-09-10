<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Account;

/**
 * Rows on one page of any list. Auto keeps each list's own default.
 *
 * @see docs/specs/account-and-auth.md §9
 *
 * @api
 */
enum RowsPerPage: string
{
    case Auto = 'auto';
    case N25 = '25';
    case N50 = '50';
    case N100 = '100';

    public function rows(): ?int
    {
        return match ($this) {
            self::Auto => null,
            self::N25 => 25,
            self::N50 => 50,
            self::N100 => 100,
        };
    }

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
