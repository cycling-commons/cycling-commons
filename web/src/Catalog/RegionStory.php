<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * A region's "History, nature and culture" text: what the land is about in
 * its historical, natural and cultural context, beside the geographic
 * "About" lead (RegionLead). One entry per language in `region.story`.
 *
 * Every entry is written through the one Text proposal flow: anyone signed
 * in proposes or translates it, a curator of the region approves it
 * (moderation-and-contribution.md §3.1c).
 *
 * The text cites its sources in place: `[the words that describe
 * it](https://address)` links those words. The linked words are the anchor,
 * so they must say what the source is ({@see self::genericAnchor()}). There
 * is no separate list of sources.
 *
 * @api
 */
final class RegionStory
{
    /**
     * The text's cap, in the characters a reader sees: a link counts its
     * words, not its brackets or its address ({@see self::readableLength()}).
     * A few paragraphs, not an article.
     */
    public const int MAX = 2500;

    /** The cap on what is stored, addresses included, so a text of many links stays bounded. */
    public const int RAW_MAX = 6000;

    /**
     * Link words that say nothing about the source, in the five site
     * languages, compared without case, spaces or end punctuation. A link
     * named this way is sent back to its writer, and the form's editor
     * marks it before a send.
     */
    public const array GENERIC_ANCHORS = [
        'source', 'sources', 'link', 'links', 'here', 'click here', 'this', 'this link', 'website', 'read more', 'more',
        'bron', 'bronnen', 'hier', 'klik hier', 'deze link', 'lees meer', 'meer',
        'lien', 'ici', 'cliquez ici', 'ce lien', 'en savoir plus', 'site',
        'quelle', 'quellen', 'hier klicken', 'dieser link', 'mehr', 'webseite',
        'fuente', 'fuentes', 'enlace', 'aquí', 'aqui', 'haz clic aquí', 'este enlace', 'más', 'leer más',
    ];

    /**
     * Paragraphs one per line: each run of line breaks starts a new
     * paragraph, other whitespace inside one folds to one space.
     */
    public static function normaliseText(string $text): string
    {
        return implode("\n\n", self::lines($text));
    }

    /**
     * The stored text as paragraphs, each split into plain text and links.
     *
     * @return list<list<array{text: string, href: ?string}>>
     */
    public static function paragraphs(string $text): array
    {
        return array_map(self::parts(...), self::lines($text));
    }

    /**
     * The length a reader sees: each `[words](address)` counts as its words.
     */
    public static function readableLength(string $text): int
    {
        $length = 0;
        foreach (self::paragraphs($text) as $parts) {
            foreach ($parts as $part) {
                $length += mb_strlen($part['text']);
            }
        }

        // The blank line between two paragraphs counts as it is stored.
        return $length + 2 * max(0, \count(self::lines($text)) - 1);
    }

    /**
     * Every link in the text, in order.
     *
     * @return list<array{words: string, href: string}>
     */
    public static function links(string $text): array
    {
        $out = [];
        foreach (self::paragraphs($text) as $parts) {
            foreach ($parts as $part) {
                if (null !== $part['href']) {
                    $out[] = ['words' => $part['text'], 'href' => $part['href']];
                }
            }
        }

        return $out;
    }

    /**
     * The first link in `$raw` whose words say nothing about its source
     * ("source", "here", "klik hier"), or null when every link names what
     * it points to.
     */
    public static function genericAnchor(string $raw): ?string
    {
        foreach (self::paragraphs($raw) as $parts) {
            foreach ($parts as $part) {
                if (null === $part['href']) {
                    continue;
                }
                $words = mb_strtolower(trim((string) preg_replace('~[\s.:;,!?()"\']+~u', ' ', $part['text'])));
                if (\in_array($words, self::GENERIC_ANCHORS, true)) {
                    return $part['text'];
                }
            }
        }

        return null;
    }

    /**
     * The stored entry for exactly one locale, or null when it has none.
     *
     * @param array<string, mixed>|null $story decoded `region.story`
     *
     * @return array{text: string, by: ?int, approvedBy: ?int}|null
     */
    public static function entry(?array $story, string $locale): ?array
    {
        $entry = $story[$locale] ?? null;
        if (!\is_array($entry) || !\is_string($entry['text'] ?? null) || '' === trim($entry['text'])) {
            return null;
        }

        return [
            'text' => trim($entry['text']),
            'by' => \is_int($entry['userId'] ?? null) ? $entry['userId'] : null,
            'approvedBy' => \is_int($entry['approvedBy'] ?? null) ? $entry['approvedBy'] : null,
        ];
    }

    /**
     * What the region page shows a reader in `$locale`: their own language,
     * else English, else the first language that has one. `lang` says which
     * it is, so the page can mark it and ask for a translation.
     *
     * @param array<string, mixed>|null $story decoded `region.story`
     *
     * `basedOn` is the language it translates, if any, and `outdated` says
     * that original changed after the translation (§3.1e).
     *
     * @return array{lang: string, paragraphs: list<list<array{text: string, href: ?string}>>, by: ?int, approvedBy: ?int, basedOn: ?string, outdated: bool}|null
     */
    public static function resolve(?array $story, string $locale): ?array
    {
        foreach (array_unique([$locale, 'en', ...RegionLead::LOCALES]) as $try) {
            $entry = self::entry($story, $try);
            if (null !== $entry) {
                return [
                    'lang' => $try,
                    'paragraphs' => self::paragraphs($entry['text']),
                    'by' => $entry['by'],
                    'approvedBy' => $entry['approvedBy'],
                ] + self::lineage($story, $try);
            }
        }

        return null;
    }

    /**
     * What a locale's entry translates and whether its original changed since:
     * the original's `at` is no longer the one the translation was made from,
     * or the original is gone. Shared with RegionLead.
     *
     * @param array<string, mixed>|null $entries
     *
     * @return array{basedOn: ?string, outdated: bool}
     */
    public static function lineage(?array $entries, string $locale): array
    {
        $based = $entries[$locale]['basedOn'] ?? null;
        if (!\is_array($based) || !\is_string($based['lang'] ?? null)) {
            return ['basedOn' => null, 'outdated' => false];
        }
        $original = $entries[$based['lang']] ?? null;

        return [
            'basedOn' => $based['lang'],
            'outdated' => !\is_array($original)
                || ($original['at'] ?? null) !== ($based['at'] ?? null)
                || ($original['submissionId'] ?? null) !== ($based['sub'] ?? null),
        ];
    }

    /**
     * `$story` with one locale's entry set, the other locales untouched.
     * `$by` wrote it, `$approvedBy` let it onto the page, `$submissionId` is
     * the approved proposal.
     *
     * @param array<string, mixed>|null $story decoded `region.story`
     *
     * @return array<string, mixed>
     */
    public static function withEntry(?array $story, string $locale, string $text, ?int $by, int $approvedBy, ?int $submissionId, \DateTimeImmutable $at): array
    {
        $story ??= [];
        $story[$locale] = [
            'text' => $text,
            'userId' => $by,
            'approvedBy' => $approvedBy,
            'submissionId' => $submissionId,
            'at' => $at->format(\DateTimeInterface::ATOM),
        ];

        return $story;
    }

    /**
     * Non-empty lines, whitespace inside each folded to one space.
     *
     * @return list<string>
     */
    private static function lines(string $raw): array
    {
        $out = [];
        foreach (preg_split('~\R~u', $raw) ?: [] as $line) {
            $line = trim(preg_replace('~\s+~u', ' ', $line) ?? '');
            if ('' !== $line) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * One paragraph as text and links: `[words](https://address)` links the
     * words. Only http and https addresses link, and only words link: a
     * bare address, or a link with any other address, stays as written.
     *
     * @return list<array{text: string, href: ?string}>
     */
    private static function parts(string $line): array
    {
        $parts = [];
        $at = 0;
        preg_match_all('~\[([^\[\]]{1,300})\]\((https?://[^\s()<>"]+)\)~i', $line, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($matches as $m) {
            [$whole, $offset] = $m[0];
            self::addText($parts, substr($line, $at, $offset - $at));
            $at = $offset + \strlen($whole);
            $words = trim($m[1][0]);
            if ('' !== $words && self::isUrl($m[2][0])) {
                $parts[] = ['text' => $words, 'href' => $m[2][0]];
            } else {
                self::addText($parts, $whole);
            }
        }
        self::addText($parts, substr($line, $at));

        return $parts;
    }

    private static function isUrl(string $url): bool
    {
        $host = parse_url($url, \PHP_URL_HOST);

        return false !== filter_var($url, \FILTER_VALIDATE_URL) && \is_string($host) && '' !== $host;
    }

    /** @param list<array{text: string, href: ?string}> $parts */
    private static function addText(array &$parts, string $text): void
    {
        if ('' === $text) {
            return;
        }
        $last = array_key_last($parts);
        if (null !== $last && null === $parts[$last]['href']) {
            $parts[$last]['text'] .= $text;

            return;
        }
        $parts[] = ['text' => $text, 'href' => null];
    }
}
