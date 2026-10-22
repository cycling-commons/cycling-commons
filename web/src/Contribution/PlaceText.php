<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

use App\Catalog\RegionLead;
use App\Catalog\RegionStory;

/**
 * A town card's or a region page's about text in one language, or a region's
 * "History, nature and culture" text, as a Text submission carries it.
 *
 * The payload names the target and the words; `changes` holds the same text
 * as one was/now pair under `text:<lang>` (`story:<lang>` for the history
 * text), which is what the desk card, the drawer and the rider's own list
 * render.
 *
 * @see docs/specs/moderation-and-contribution.md §3.1b and §3.1c
 *
 * @api
 */
final class PlaceText
{
    public const string TOWN = 'town';
    /** A region's geography text: where it lies, its landscape, size and neighbours (the page's first text, RegionLead). */
    public const string REGION = 'region';
    /** A region's history, nature and culture text (RegionStory). */
    public const string STORY = 'story';

    /**
     * The row icon of a Text submission, by target, drawn in the 24-box of
     * the category icons (partials/_type_icon.html.twig, via
     * `cc_text_icons()`): a text has no catalogue letter, so it has no
     * category icon of its own (owner 2026-10-01: "add a city or a region
     * icon"). Town: two houses with windows. Region: a folded map. A
     * region's history text: an open book.
     */
    public const array ICONS = [
        self::TOWN => 'M2 21V11.5l4.5-3.5 4.5 3.5V21Z M12 21V6.5l5-4 5 4V21Z M5.5 14h2v2h-2Z M15.5 8.5h3v2h-3Z M15.5 12.5h3v2h-3Z M15.5 16.5h3v2h-3Z',
        self::REGION => 'M2 5l6.5-2.5 7 2.5L22 2.5V19l-6.5 2.5-7-2.5L2 21.5Z M8 4.5h1v14h-1Z M15 7h1v13h-1Z',
        self::STORY => 'M2 5c3.3-1.4 6.3-1.2 9 .6V20.5c-2.7-1.6-5.7-1.8-9-.6Z M13 5.6c2.7-1.8 5.7-2 9-.6v14.9c-3.3-1.2-6.3-1-9 .6Z',
    ];

    /** Same cap for both: a region's lead and a town card's paragraph. The history text has its own ({@see RegionStory::MAX}, {@see RegionStory::RAW_MAX}). */
    public const int MAX = 1200;

    /** The rider's note to the curator. */
    public const int NOTE_MAX = 2000;

    /**
     * Every language a stored text or proposal may name: the languages the
     * build carries. A new text is written only in one this deployment
     * serves ({@see \App\Routing\Languages::options()}).
     */
    public const array LANGS = RegionLead::LOCALES;

    /**
     * The form a Text submission was written on, as a route and its
     * parameters, so its rider can open it again from their list: the town
     * text with the town's name, or the region's geography or history text
     * by the region's slug. Null for a payload without what the route needs.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{0: string, 1: array<string, string>}|null
     */
    public static function formRoute(array $payload, string $title): ?array
    {
        $lang = $payload['lang'] ?? null;
        $ref = $payload['ref'] ?? null;
        $slug = $payload['slug'] ?? null;
        if (!\is_string($lang)) {
            return null;
        }

        return match ($payload['target'] ?? null) {
            self::TOWN => \is_string($ref) && 1 === preg_match('~^(node|way|relation)/(\d{1,16})$~', $ref, $m)
                ? ['town_text', ['osmType' => $m[1], 'osmId' => $m[2], 'lang' => $lang, 'name' => $title]]
                : null,
            self::REGION, self::STORY => \is_string($slug) && '' !== $slug
                ? [self::STORY === $payload['target'] ? 'region_story' : 'region_text', ['slug' => $slug, 'lang' => $lang]]
                : null,
            default => null,
        };
    }

    /** The `changes` key for one language's text: `text:nl`, or `story:nl` for a region's history text. */
    public static function changeKey(string $lang, string $target = self::TOWN): string
    {
        return (self::STORY === $target ? 'story:' : 'text:').$lang;
    }

    /** The language a `changes` key names, or null when it is no text key. */
    public static function langOfKey(string $key): ?string
    {
        return 1 === preg_match('/^text:([a-z]{2})$/', $key, $m) ? $m[1] : null;
    }

    /** The language a history-text `changes` key names (`story:nl`), or null when it is none. */
    public static function storyLangOfKey(string $key): ?string
    {
        return 1 === preg_match('/^story:([a-z]{2})$/', $key, $m) ? $m[1] : null;
    }

    /** One paragraph: runs of whitespace fold to one space, as the town page always did. */
    public static function normalise(string $text): string
    {
        return trim(preg_replace('~\s+~u', ' ', $text) ?? '');
    }

    /**
     * The target a payload names, or null when it names none.
     *
     * `ref` is `node/123` for a town and the region id (as a string) for a
     * region and its history text.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{target: string, ref: string, lang: string, text: string, derived: bool}|null
     */
    public static function fromPayload(array $payload): ?array
    {
        $target = $payload['target'] ?? null;
        $ref = $payload['ref'] ?? null;
        $lang = $payload['lang'] ?? null;
        $text = $payload['text'] ?? null;
        $max = self::STORY === $target ? RegionStory::RAW_MAX : self::MAX;
        if (!\is_string($lang) || !\in_array($lang, self::LANGS, true) || !\is_string($text) || '' === $text || mb_strlen($text) > $max) {
            return null;
        }
        $validRef = match ($target) {
            self::TOWN => \is_string($ref) && 1 === preg_match('~^(node|way|relation)/\d{1,16}$~', $ref),
            self::REGION, self::STORY => (\is_int($ref) || \is_string($ref)) && 1 === preg_match('~^\d{1,16}$~', (string) $ref),
            default => false,
        };
        if (!$validRef) {
            return null;
        }

        return [
            'target' => $target,
            'ref' => (string) $ref,
            'lang' => $lang,
            'text' => $text,
            // The history text has no Wikipedia article to credit.
            'derived' => self::STORY !== $target && true === ($payload['derived'] ?? null),
        ];
    }

    /**
     * JSONB as an array, or null. Malformed JSON reads as nothing.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(mixed $raw): ?array
    {
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }
        $decoded = json_decode($raw, true);

        /* @var array<string, mixed>|null */
        return \is_array($decoded) ? $decoded : null;
    }
}
