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
    public const string VERSION = 'v3';
    public const string TEXT_KEY = 'media.consent.contract';

    public static function hash(string $text): string
    {
        return hash('sha256', $text);
    }
}
