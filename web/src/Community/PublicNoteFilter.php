<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Community;

/**
 * Hardening for the two stranger-writable free-text fields
 * (2026-07-29-country-requests-and-curator-signup-design.md §7).
 *
 * Both fields are reviewer-only and never rendered on a public page, which
 * bounds the blast radius to the reviewer's own screen. This filter is the
 * layer above that: it rejects the payloads outright rather than relying on
 * escaping alone.
 *
 * The claims about what is wrong on the map do NOT come through here — those
 * go through the contribute wizard as normal submissions (§8), which is what
 * keeps these fields short enough to be safe.
 */
final class PublicNoteFilter
{
    public const int MAX_NOTE = 280;
    public const int MAX_ABOUT = 1200;

    /**
     * Zero-width and bidirectional-override characters. Invisible on screen,
     * so a reviewer cannot see what they are approving.
     */
    private const string INVISIBLE = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u';

    /**
     * A link is the entire payload of most spam and nobody needs one to explain
     * why they know an area. Matches a scheme, a bare www., and a bare
     * domain.tld — the three forms that survive a reviewer's eye.
     */
    private const string LINKISH = '~(\b[a-z][a-z0-9+.-]*:(//)?[^\s]|\bwww\.|\b[a-z0-9-]+\.[a-z]{2,}(/|\b))~i';

    public function clean(string $raw, int $maxLength): string
    {
        $text = $raw;

        if (class_exists(\Normalizer::class)) {
            // Composed vs decomposed forms must not store as two different
            // values, or the uniqueness rules can be evaded visually.
            $text = (string) \Normalizer::normalize($text, \Normalizer::FORM_C);
        }

        $text = (string) preg_replace(self::INVISIBLE, '', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);

        // Control characters other than the whitespace already collapsed above.
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
