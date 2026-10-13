<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Api\V1\Dto;

/**
 * One photo as the API serves it (docs/specs/public-api.md §5): the picture,
 * its licence and its credit, so a consumer can show all three.
 *
 * A rider's photo is credited by link, never by a name
 * (docs/specs/photo-uploads.md §1, item 3c): `credit_url` is its photo page,
 * which names the photographer as they chose at the moment it is read, so a
 * rider who goes private or leaves is not left named in a consumer's copy.
 * A Wikimedia Commons photo carries the author string and links Commons
 * publishes. Never the alt text or the place the photo was taken: both are
 * a rider's own words or whereabouts.
 */
final class PhotoEntry
{
    /** The closed key list, in order. */
    public const array KEYS = ['url', 'thumb', 'licence', 'credit', 'credit_url', 'source'];

    /**
     * @param array<array-key, mixed> $entry a stored `photo`/`photos` entry, already sifted
     *
     * @return array{url: string, thumb: string|null, licence: string|null, credit: string|null, credit_url: string|null, source: string|null}|null
     */
    public static function of(array $entry, string $siteUrl): ?array
    {
        $url = self::http($entry['lg'] ?? null);
        if (null === $url) {
            return null;
        }
        $uuid = $entry['id'] ?? null;
        $rider = \is_string($uuid) && 1 === preg_match('~^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$~i', $uuid);

        return [
            'url' => $url,
            'thumb' => self::http($entry['sm'] ?? null),
            'licence' => \is_string($entry['license'] ?? null) && '' !== $entry['license'] ? $entry['license'] : null,
            'credit' => $rider ? null : self::text($entry['credit'] ?? null),
            'credit_url' => $rider ? rtrim($siteUrl, '/').'/photo/'.strtolower($uuid) : self::http($entry['creditUrl'] ?? null),
            'source' => $rider ? null : self::http($entry['source'] ?? null),
        ];
    }

    /**
     * Every photo of an attribute set, the gallery first.
     *
     * @param array<string, mixed> $attributes
     *
     * @return list<array{url: string, thumb: string|null, licence: string|null, credit: string|null, credit_url: string|null, source: string|null}>
     */
    public static function allOf(array $attributes, string $siteUrl): array
    {
        $entries = \is_array($attributes['photos'] ?? null) ? array_values($attributes['photos']) : [];
        if (\is_array($attributes['photo'] ?? null)) {
            $entries[] = $attributes['photo'];
        }
        $out = [];
        $seen = [];
        foreach ($entries as $entry) {
            $photo = \is_array($entry) ? self::of($entry, $siteUrl) : null;
            if (null !== $photo && !isset($seen[$photo['url']])) {
                $seen[$photo['url']] = true;
                $out[] = $photo;
            }
        }

        return $out;
    }

    private static function http(mixed $value): ?string
    {
        return \is_string($value) && 1 === preg_match('~^https?://~i', $value) ? $value : null;
    }

    private static function text(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
