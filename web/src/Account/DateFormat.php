<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Account;

/**
 * How a rider wants dates written. Independent of language; Auto follows the locale.
 *
 * @see docs/specs/account-and-auth.md §9
 *
 * @api
 */
enum DateFormat: string
{
    case Auto = 'auto';
    /** 2026-08-01 */
    case Ymd = 'ymd';
    /** 01-08-2026 */
    case Dmy = 'dmy';
    /** 08/01/2026 */
    case Mdy = 'mdy';
    /** 1 August 2026 — month name in the reader's language. */
    case Long = 'long';

    /** ICU pattern, or null when Auto/Long should use the locale. */
    public function pattern(): ?string
    {
        return match ($this) {
            self::Auto, self::Long => null,
            self::Ymd => 'yyyy-MM-dd',
            self::Dmy => 'dd-MM-yyyy',
            self::Mdy => 'MM/dd/yyyy',
        };
    }

    public function localeDateStyle(): int
    {
        return self::Long === $this ? \IntlDateFormatter::LONG : \IntlDateFormatter::MEDIUM;
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Auto => 'form.date_format_auto',
            self::Ymd => 'form.date_format_ymd',
            self::Dmy => 'form.date_format_dmy',
            self::Mdy => 'form.date_format_mdy',
            self::Long => 'form.date_format_long',
        };
    }
}
