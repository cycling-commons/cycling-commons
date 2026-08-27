<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

/**
 * Own-work CC BY-SA 4.0 consent. VERSION is the only re-consent trigger.
 *
 * @see docs/specs/photo-uploads.md §1, §4
 *
 * @api
 */
final class MediaConsent
{
    public const string KIND = 'media-cc-by-sa';
    // Wording change must bump VERSION; text_hash is evidence of the exact words.
    /**
     * v4 (2026-08-28): the contract now also says the photo is not AI-generated.
     * Bumped because the wording is hashed onto every consent record, and a
     * record has to point at what was actually agreed. v3 records stay valid
     * evidence of the v3 promise.
     */
    public const string VERSION = 'v4';
    public const string TEXT_KEY = 'media.consent.contract';

    public static function hash(string $text): string
    {
        return hash('sha256', $text);
    }
}
