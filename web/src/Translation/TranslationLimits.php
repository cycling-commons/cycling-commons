<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Translation;

/**
 * Hard limits for in-site translation proposals.
 *
 * Measured max English/locale value length across messages.*.yaml: 2821
 * (key export.readme). Cap = max(2821 + 2048, 4096) = 4869.
 * Longest message_key length: 60 (fits VARCHAR(255)).
 *
 * @see docs/specs/translations.md
 *
 * @api
 */
final class TranslationLimits
{
    public const int PROPOSED_VALUE_MAX = 4869;

    /** Locales a rider may propose (translations.md §1). */
    public const array LOCALES = ['fr', 'nl', 'de', 'es'];

    /** Locales an overlay may carry: the rider locales plus English (translations.md §3). */
    public const array OVERLAY_LOCALES = ['en', 'fr', 'nl', 'de', 'es'];

    public static function isTranslatableLocale(string $locale): bool
    {
        return \in_array($locale, self::LOCALES, true);
    }

    public static function isOverlayLocale(string $locale): bool
    {
        return \in_array($locale, self::OVERLAY_LOCALES, true);
    }
}
