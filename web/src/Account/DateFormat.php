<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Account;

/**
 * How a rider wants dates written (docs/specs/account-and-auth.md §9).
 *
 * A separate preference from language on purpose. The two really are
 * independent: plenty of people read a site in English and still expect
 * 01-08-2026, and `2026-08-01` reads as a filename to most of Europe. Tying
 * the format to the interface language would give those riders no way to say so.
 *
 * `Auto` is the default and means "whatever suits the language I am reading" —
 * ICU's medium form for the active locale, which is already the right answer
 * for most people. The rest are explicit overrides that mean the same thing in
 * every locale: the pattern is fixed, only the month NAMES localise.
 *
 * @api User-preference vocabulary; consumed by SettingsType, User and DateDisplayExtension.
 */
enum DateFormat: string
{
    case Auto = 'auto';
    /** 2026-08-01 — ISO 8601, unambiguous, and what the app used everywhere before this existed. */
    case Ymd = 'ymd';
    /** 01-08-2026 — day first, the common written form across most of Europe. */
    case Dmy = 'dmy';
    /** 08/01/2026 — month first. */
    case Mdy = 'mdy';
    /** 1 August 2026 — written out, with the month name in the reader's language. */
    case Long = 'long';

    /**
     * The ICU date pattern, or null when the locale's own medium form should be
     * used instead (Auto and Long, whose whole point is to follow the language).
     */
    public function pattern(): ?string
    {
        return match ($this) {
            self::Auto, self::Long => null,
            self::Ymd => 'yyyy-MM-dd',
            self::Dmy => 'dd-MM-yyyy',
            self::Mdy => 'MM/dd/yyyy',
        };
    }

    /** ICU's own date style, for the two cases that defer to the locale. */
    public function localeDateStyle(): int
    {
        return self::Long === $this ? \IntlDateFormatter::LONG : \IntlDateFormatter::MEDIUM;
    }

    /** The translation key for this option's label in the settings dropdown. */
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
