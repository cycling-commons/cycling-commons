<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

/**
 * A place's `photo` / `photos` as a public payload serves them.
 *
 * A stored photo entry carries what PhotoValidator needs to decide where the
 * camera stood: a rider upload's `distanceM` from the pin and the
 * `distancePin` it was measured to, a curator's `locationConfirmed` and the
 * `confirmedPin` it was made at, a Commons file's `cameraAt`. Together with
 * the pin they say where the photographer was, and the public map shows none
 * of them, so a public payload leaves them out. The stored attributes keep
 * them, and the curator-only views (the pending card, hidden photos) read
 * them there.
 *
 * Apply it after PhotoValidator::sift(), which needs them.
 *
 * @see docs/specs/photo-uploads.md §5g
 *
 * @api
 */
final class PublicPhotos
{
    /** Keys of a stored photo entry a public payload never carries. */
    public const array PRIVATE_KEYS = ['distanceM', 'distancePin', 'locationConfirmed', 'confirmedPin', 'cameraAt'];

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    public static function of(array $attributes): array
    {
        if (\is_array($attributes['photo'] ?? null)) {
            $attributes['photo'] = self::entry($attributes['photo']);
        }
        if (\is_array($attributes['photos'] ?? null)) {
            $attributes['photos'] = array_map(
                static fn (mixed $entry): mixed => \is_array($entry) ? self::entry($entry) : $entry,
                $attributes['photos'],
            );
        }

        return $attributes;
    }

    /**
     * @param array<array-key, mixed> $entry
     *
     * @return array<array-key, mixed>
     */
    private static function entry(array $entry): array
    {
        return array_diff_key($entry, array_flip(self::PRIVATE_KEYS));
    }
}
