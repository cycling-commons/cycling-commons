<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Account;

/**
 * 14:30 or 2:30 PM. Independent of {@see DateFormat}.
 *
 * @see docs/specs/account-and-auth.md §9
 *
 * @api
 */
enum TimeFormat: string
{
    case Auto = 'auto';
    case H24 = 'h24';
    case H12 = 'h12';

    /** ICU time pattern, or null to use the locale's short form. */
    public function pattern(): ?string
    {
        return match ($this) {
            self::Auto => null,
            self::H24 => 'HH:mm',
            self::H12 => 'h:mm a',
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Auto => 'form.time_format_auto',
            self::H24 => 'form.time_format_h24',
            self::H12 => 'form.time_format_h12',
        };
    }
}
