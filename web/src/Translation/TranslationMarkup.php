<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Translation;

/**
 * Is the markup in a proposed translation well formed, and allowed?
 *
 * **Why this exists.** Catalogue strings carry markup: `<b>WCAG 2.2, level
 * AA</b>` inside a sentence, `<code>` around a term, a `<a href>` in a hint.
 * A translator sees that markup in the box they type into, and many of them
 * are not developers. A dropped `</b>`, a `<b>` typed as `<b `, or a tag
 * invented to add emphasis all reach the catalogue and then the page.
 *
 * Three things can go wrong and they are not equally bad:
 *
 * 1. **Unbalanced or malformed.** `<b>text` with no close bleeds bold into
 *    the rest of the page. The sanitizer FIXES this at render, which means
 *    nobody ever sees it as an error and the catalogue quietly holds broken
 *    markup forever. Caught here instead, while the person who wrote it is
 *    still looking at it.
 * 2. **A tag we do not allow.** `<script>`, `<img>`, `<style>`. Rendering is
 *    already safe: `|rich` sanitises to the allowlist in
 *    `config/packages/html_sanitizer.yaml`. But a translation whose tags get
 *    silently stripped is a translation that does not say what the translator
 *    wrote, and they are never told.
 * 3. **The tags do not match the English.** A translator who drops the `<b>`
 *    around the level, or adds one somewhere else, changes the emphasis of a
 *    sentence a curator is about to approve at a glance. A warning rather than
 *    a refusal: languages move emphasis, and sometimes they are right.
 *
 * **This is not a security boundary.** `|rich` is, and it runs whatever
 * happens. This is a helpfulness boundary: it stops a translator submitting
 * something that will not render the way they meant, and it stops a curator
 * approving markup nobody checked.
 *
 * @see docs/specs/translations.md §7
 *
 * @api
 */
final class TranslationMarkup
{
    /**
     * The tags a catalogue string may carry.
     *
     * Deliberately the same list as the `app.rich_translations` sanitizer, and
     * that is the whole point: anything outside it is stripped at render, so
     * accepting it here would accept something that cannot appear.
     *
     * Kept as a constant rather than read from the sanitizer because the
     * sanitizer's configuration is not introspectable at runtime.
     * `TranslationMarkupTest` asserts the two agree.
     */
    public const array ALLOWED = ['b', 'strong', 'i', 'em', 'code', 'br', 'span', 'a'];

    /** Tags that stand alone and must never carry a closing partner. */
    private const array VOID = ['br'];

    /**
     * Every problem with this string, worst first. Empty means it is fine.
     *
     * @return list<array{level: 'error'|'warning', key: string, tag?: string}>
     */
    public static function check(string $value, ?string $english = null): array
    {
        $problems = [];

        // A lone `<` that is not a tag: almost always a translator writing
        // "<" as a character. It is legal HTML text, so it is a warning, but
        // it is worth saying because it usually means a broken tag.
        if (1 === preg_match('/<(?![a-zA-Z\/!])/', $value)) {
            $problems[] = ['level' => 'warning', 'key' => 'stray_angle'];
        }

        $tags = self::tags($value);

        foreach ($tags as $tag) {
            if (!\in_array($tag['name'], self::ALLOWED, true)) {
                $problems[] = ['level' => 'error', 'key' => 'tag_not_allowed', 'tag' => $tag['name']];
            }
        }

        $unbalanced = self::unbalanced($tags);
        foreach ($unbalanced as $name) {
            $problems[] = ['level' => 'error', 'key' => 'unbalanced', 'tag' => $name];
        }

        if (null !== $english && [] === array_filter($problems, static fn (array $p): bool => 'error' === $p['level'])) {
            foreach (self::tagDrift(self::tags($english), $tags) as $name) {
                $problems[] = ['level' => 'warning', 'key' => 'tag_drift', 'tag' => $name];
            }
        }

        // Errors first: a reader fixes what blocks them before what advises them.
        usort($problems, static fn (array $a, array $b): int => ('error' === $a['level'] ? 0 : 1) <=> ('error' === $b['level'] ? 0 : 1));

        return $problems;
    }

    /** Nothing here will render wrong or be stripped. */
    public static function isAcceptable(string $value, ?string $english = null): bool
    {
        foreach (self::check($value, $english) as $problem) {
            if ('error' === $problem['level']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every tag in the string, in order.
     *
     * A regex rather than a parser: the input is one sentence with a handful
     * of inline tags, DOM would need a wrapper element and would silently
     * repair exactly the mistakes this class is looking for.
     *
     * @return list<array{name: string, closing: bool, selfClosing: bool}>
     */
    private static function tags(string $value): array
    {
        preg_match_all('/<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9]*)\b[^>]*?(\/?)\s*>/', $value, $m, \PREG_SET_ORDER);

        $out = [];
        foreach ($m as $match) {
            $out[] = [
                'name' => strtolower($match[2]),
                'closing' => '' !== $match[1],
                'selfClosing' => '' !== $match[3],
            ];
        }

        return $out;
    }

    /**
     * Tag names that open and never close, or close and never open.
     *
     * @param list<array{name: string, closing: bool, selfClosing: bool}> $tags
     *
     * @return list<string>
     */
    private static function unbalanced(array $tags): array
    {
        $stack = [];
        $bad = [];

        foreach ($tags as $tag) {
            if (\in_array($tag['name'], self::VOID, true)) {
                // `<br>` and `<br />` are both right; `</br>` is not.
                if ($tag['closing']) {
                    $bad[] = $tag['name'];
                }
                continue;
            }
            if ($tag['selfClosing']) {
                continue;
            }

            if ($tag['closing']) {
                $open = array_pop($stack);
                if (null === $open || $open !== $tag['name']) {
                    $bad[] = $tag['name'];
                    if (null !== $open) {
                        // Put it back: `<b><i></b></i>` is one crossing, not two
                        // separate unclosed tags.
                        $stack[] = $open;
                    }
                }
                continue;
            }

            $stack[] = $tag['name'];
        }

        foreach ($stack as $stillOpen) {
            $bad[] = $stillOpen;
        }

        return array_values(array_unique($bad));
    }

    /**
     * Tags the English has and the translation does not, or the other way.
     *
     * Counted, not just present: a sentence with two `<b>` spans that comes
     * back with one has lost an emphasis, and that is the common case.
     *
     * @param list<array{name: string, closing: bool, selfClosing: bool}> $english
     * @param list<array{name: string, closing: bool, selfClosing: bool}> $proposed
     *
     * @return list<string>
     */
    private static function tagDrift(array $english, array $proposed): array
    {
        $count = static function (array $tags): array {
            $out = [];
            foreach ($tags as $tag) {
                if (!$tag['closing']) {
                    $out[$tag['name']] = ($out[$tag['name']] ?? 0) + 1;
                }
            }

            return $out;
        };

        $a = $count($english);
        $b = $count($proposed);

        $drift = [];
        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $name) {
            if (($a[$name] ?? 0) !== ($b[$name] ?? 0)) {
                $drift[] = (string) $name;
            }
        }

        return $drift;
    }
}
