<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Account;

/**
 * Whether a rider reads 14:30 or 2:30 PM (docs/specs/account-and-auth.md §9).
 *
 * Its own preference, not something inferred from the date format. An earlier
 * version derived it — month-first implies twelve-hour — which was tidy
 * reasoning and wrong for real people: someone can want 01-08-2026 and 2:30 PM,
 * or 08/01/2026 and 14:30, and a clock convention guessed from a date order is
 * a guess about somebody's habits made from the wrong evidence.
 *
 * @api User-preference vocabulary; consumed by SettingsType, User and DateDisplayExtension.
 */
enum TimeFormat: string
{
    case Auto = 'auto';
    case H24 = 'h24';
    case H12 = 'h12';

    /** The ICU time pattern, or null to use the locale's own short form. */
    public function pattern(): ?string
    {
        return match ($this) {
            self::Auto => null,
            self::H24 => 'HH:mm',
            self::H12 => 'h:mm a',
        };
    }

    /** The translation key for this option's label in the settings dropdown. */
    public function labelKey(): string
    {
        return match ($this) {
            self::Auto => 'form.time_format_auto',
            self::H24 => 'form.time_format_h24',
            self::H12 => 'form.time_format_h12',
        };
    }
}
