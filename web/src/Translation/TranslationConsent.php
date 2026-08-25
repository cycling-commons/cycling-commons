<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation;

/**
 * Own-work CC BY-SA 4.0 consent for in-site translation proposals.
 * VERSION is the only re-consent trigger.
 *
 * @see docs/specs/translations.md §4, §6
 *
 * @api
 */
final class TranslationConsent
{
    public const string KIND = 'translation-cc-by-sa';
    public const string VERSION = 'v1';
    public const string TEXT_KEY = 'translate.consent.contract';

    public static function hash(string $text): string
    {
        return hash('sha256', $text);
    }
}
