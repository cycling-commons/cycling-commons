<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation;

/**
 * Invisible marks around a translated string (translations.md §4.1).
 *
 *   U+2061  [21 x (U+200B | U+200C)]  text  U+2062
 *
 * Twenty bits of translation_entry.id, most significant first, then one stale
 * bit. Plain text, so auto-escape, |rich and the HTML sanitizer pass it
 * through untouched, and a page with JavaScript off looks entirely normal.
 *
 * The decoder is assets/js/translate-mode.js; tests/js/fixtures/translate-marks.json
 * pins both sides to the same bytes, so the two languages cannot drift.
 *
 * @api
 */
final class MarkerCodec
{
    public const string START = "\u{2061}";
    public const string END = "\u{2062}";
    public const string ZERO = "\u{200B}";
    public const string ONE = "\u{200C}";

    public const int ID_BITS = 20;
    public const int MAX_ID = (1 << self::ID_BITS) - 1;

    /**
     * One START followed by exactly 21 bit characters, or one END.
     *
     * Never a bare zero-width character: a catalogue string may legitimately
     * contain one, and the two response nets (translations.md §4.1) strip this
     * pattern, not the characters.
     */
    public const string PATTERN = '/\x{2061}[\x{200B}\x{200C}]{21}|\x{2062}/u';

    /**
     * The same marks as {@see PATTERN}, in the form json_encode() writes them
     * when its options lack JSON_UNESCAPED_UNICODE (translations.md §4.1).
     * Symfony's JsonResponse encodes with options 15 (the four HEX flags), and
     * boot.js.twig pipes through |json_encode with the same four flags, so
     * neither body ever carries a raw U+2061 byte: every mark comes out as the
     * literal ASCII text \u2061\u200b...\u2062. This pattern matches that
     * literal backslash-u text, not the code points, so it needs no /u
     * modifier.
     */
    public const string PATTERN_JSON = '/\\\\u2061(?:\\\\u200[bc]){21}|\\\\u2062/i';

    /**
     * The marked form of $text, or $text unchanged when $id will not fit in
     * ID_BITS bits (there is nothing the browser could decode).
     */
    public static function wrap(int $id, bool $stale, string $text): string
    {
        if ($id < 1 || $id > self::MAX_ID) {
            return $text;
        }

        $bits = str_pad(decbin($id), self::ID_BITS, '0', \STR_PAD_LEFT).($stale ? '1' : '0');

        return self::START.strtr($bits, ['0' => self::ZERO, '1' => self::ONE]).$text.self::END;
    }

    /**
     * The string with every mark removed, and the string itself when it cannot
     * be scanned.
     *
     * The `?? $s` is load-bearing. `preg_replace` with the `u` flag returns
     * NULL on malformed UTF-8, and a cast of that to string is the empty
     * string, so a strip applied blindly to a body this cannot parse would
     * replace it with nothing: no error, no log, a corrupt download. The
     * response net (translations.md §4.1) strips everything that is not
     * `text/html`, and this site serves binary bodies (the GDPR export ZIP,
     * the streamed rulebook PDF, the image proxies). A body that is not text
     * cannot contain a mark, so returning it untouched is both safe and right.
     */
    public static function strip(string $s): string
    {
        return preg_replace(self::PATTERN, '', $s) ?? $s;
    }

    /**
     * Raw marks and JSON-escaped marks, for the two nets that keep a mark
     * from leaving the server outside HTML (translations.md §4.1):
     * {@see MarkStripResponseSubscriber} for every non-HTML response,
     * {@see MarkStripMailSubscriber} for mail. Never bare zero-width
     * characters, so a catalogue string that legitimately contains one
     * survives untouched.
     *
     * Carries the same `?? $s` guard as {@see strip()}, for the same reason:
     * `preg_replace()` given an array of patterns still answers NULL for the
     * whole call when the subject fails the `/u` pattern on malformed UTF-8,
     * and a cast of that to string would blank the body instead of leaving it
     * unchanged.
     */
    public static function stripAllForms(string $s): string
    {
        return preg_replace([self::PATTERN, self::PATTERN_JSON], '', $s) ?? $s;
    }
}
