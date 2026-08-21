<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Community;

/**
 * Hardening for stranger-writable notes. Reviewer-only; never public.
 *
 * @see docs/specs/moderation-and-contribution.md §11
 */
final class PublicNoteFilter
{
    public const int MAX_NOTE = 280;
    public const int MAX_ABOUT = 1200;

    /** Zero-width / bidi controls; invisible on screen. */
    private const string INVISIBLE = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u';

    /** Scheme, www., or domain.tld — mailto/tel only with a real payload. */
    private const string LINKISH = '~(\b[a-z][a-z0-9+.-]*://\S|\bmailto:[^\s@]+@|\btel:[+0-9]|\bwww\.|\b[a-z0-9-]+\.[a-z]{2,}(/|\b))~i';

    public function clean(string $raw, int $maxLength): string
    {
        $text = $raw;

        if (class_exists(\Normalizer::class)) {
            $text = (string) \Normalizer::normalize($text, \Normalizer::FORM_C);
        }

        $text = (string) preg_replace(self::INVISIBLE, '', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);

        if (1 === preg_match('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}]/u', $text)) {
            throw new InvalidNoteException('control', 'The text contains control characters.');
        }

        if (1 === preg_match(self::LINKISH, $text)) {
            throw new InvalidNoteException('link', 'Links are not accepted in this field.');
        }

        if (mb_strlen($text) > $maxLength) {
            throw new InvalidNoteException('length', sprintf('The text is longer than %d characters.', $maxLength));
        }

        return $text;
    }
}
