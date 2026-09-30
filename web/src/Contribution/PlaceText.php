<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

use App\Catalog\RegionLead;

/**
 * A town card's or a region page's about text in one language, as a Text
 * submission carries it.
 *
 * The payload names the target and the words; `changes` holds the same text
 * as one was/now pair under `text:<lang>`, which is what the desk card, the
 * drawer and the rider's own list render.
 *
 * @see docs/specs/moderation-and-contribution.md §3.1b
 *
 * @api
 */
final class PlaceText
{
    public const string TOWN = 'town';
    public const string REGION = 'region';

    /** Same cap for both: a region's lead and a town card's paragraph. */
    public const int MAX = 1200;

    /** The rider's note to the curator. */
    public const int NOTE_MAX = 2000;

    /** The languages a text can be written in: the site's own set. */
    public const array LANGS = RegionLead::LOCALES;

    /** The `changes` key for one language: `text:nl`. */
    public static function changeKey(string $lang): string
    {
        return 'text:'.$lang;
    }

    /** The language a `changes` key names, or null when it is no text key. */
    public static function langOfKey(string $key): ?string
    {
        return 1 === preg_match('/^text:([a-z]{2})$/', $key, $m) ? $m[1] : null;
    }

    /** One paragraph: runs of whitespace fold to one space, as the town page always did. */
    public static function normalise(string $text): string
    {
        return trim(preg_replace('~\s+~u', ' ', $text) ?? '');
    }

    /**
     * The target a payload names, or null when it names none.
     *
     * `ref` is `node/123` for a town and the region id (as a string) for a region.
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
        if (!\is_string($lang) || !\in_array($lang, self::LANGS, true) || !\is_string($text) || '' === $text || mb_strlen($text) > self::MAX) {
            return null;
        }
        $validRef = match ($target) {
            self::TOWN => \is_string($ref) && 1 === preg_match('~^(node|way|relation)/\d{1,16}$~', $ref),
            self::REGION => (\is_int($ref) || \is_string($ref)) && 1 === preg_match('~^\d{1,16}$~', (string) $ref),
            default => false,
        };
        if (!$validRef) {
            return null;
        }

        return ['target' => $target, 'ref' => (string) $ref, 'lang' => $lang, 'text' => $text, 'derived' => true === ($payload['derived'] ?? null)];
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
