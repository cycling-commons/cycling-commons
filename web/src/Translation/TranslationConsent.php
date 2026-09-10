<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Translation;

/**
 * Own-work AGPL-3.0-only consent for in-site translation proposals.
 * VERSION is the only re-consent trigger.
 *
 * @see docs/specs/translations.md §4, §6
 *
 * @api
 */
final class TranslationConsent
{
    /**
     * The kind changed with the licence on 2026-09-10. A UI translation is a
     * derivative of the English string it renders, so it follows the code
     * licence and cannot sit under a separate one. Old 'translation-cc-by-sa'
     * rows are left in the ledger untouched: they are true evidence of what
     * was agreed while that promise stood, and nothing may rewrite them.
     */
    public const string KIND = 'translation-agpl';
    // Wording change must bump VERSION; text_hash is evidence of the exact words.
    /**
     * v2 (2026-09-10): the contract now grants AGPL-3.0-only instead of
     * CC BY-SA 4.0. Bumped because the wording is hashed onto every consent
     * record, and a record has to point at what was actually agreed. Anyone
     * holding a v1 record is asked again rather than carried across, which is
     * the whole point of keying standing consent on the version.
     */
    public const string VERSION = 'v2';
    public const string TEXT_KEY = 'translate.consent.contract';

    public static function hash(string $text): string
    {
        return hash('sha256', $text);
    }
}
