<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

/**
 * The consent contract riders grant before a single byte may be uploaded:
 * own work, licensed CC BY-SA 4.0 (docs/specs/photo-uploads.md §1.5, §4).
 *
 * VERSION is the re-consent trigger and the ONLY one. A rider who has a record
 * at the current version is never asked again; bumping VERSION means a new
 * consent act for everyone, never a silent carry-over. TEXT_KEY is rendered
 * through the translator, so the hash stored on the record is the sha256 of
 * the exact words that rider saw, in that rider's language — the evidence of
 * what was agreed to, while VERSION is what decides whether to ask again.
 *
 * @api Read by ConsentService and the wizard's consent endpoints.
 */
final class MediaConsent
{
    public const string KIND = 'media-cc-by-sa';
    // v2 (2026-07-31): the contract became a first-person acknowledgement — "I
    // agree to license my photos…" rather than a statement of fact about the
    // licence — and now links the licence deed. Different words mean a
    // different agreement, so every rider is asked again rather than having
    // v1's hash silently stand for wording they never saw.
    public const string VERSION = 'v2';
    public const string TEXT_KEY = 'media.consent.contract';

    public static function hash(string $text): string
    {
        return hash('sha256', $text);
    }
}
