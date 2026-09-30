<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * A rider's `rider#xxxxxxxx` handle for curator-facing views and the public
 * change history.
 *
 * The eight characters are drawn at random once, when the account is created,
 * and stored on it (`users.pseudonym`, {@see \App\Entity\User}): Crockford
 * base32 in lower case without i, l, o and u, so 32^8 (about 10^12) names and
 * nothing to compute from an id. They never change, whatever the rider does
 * with their profile or display name.
 *
 * An account that no longer exists has no stored pseudonym. Its old work shows
 * the handle derived from its id: `rider#` and the first four hex characters of
 * crc32b('cc-sub-'.id), the handle every rider had before pseudonyms were
 * stored.
 *
 * @see docs/specs/account-and-auth.md §9
 *
 * @api
 */
final class RiderPseudonym
{
    public const string PREFIX = 'rider#';

    public const int LENGTH = 8;

    public const string ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

    /** Eight random characters from ALPHABET. */
    public static function random(): string
    {
        $out = '';
        for ($i = 0; $i < self::LENGTH; ++$i) {
            $out .= self::ALPHABET[random_int(0, 31)];
        }

        return $out;
    }

    public static function isValid(mixed $pseudonym): bool
    {
        return \is_string($pseudonym) && 1 === preg_match('/^[0-9a-hjkmnp-tv-z]{8}$/', $pseudonym);
    }

    /**
     * The handle a view shows: the stored pseudonym, or for an account that no
     * longer exists (`$stored` null) the handle derived from its id.
     */
    public static function handle(mixed $stored, int|string $userId): string
    {
        if (\is_string($stored) && self::isValid($stored)) {
            return self::PREFIX.$stored;
        }

        return self::PREFIX.substr(hash('crc32b', 'cc-sub-'.$userId), 0, 4);
    }
}
