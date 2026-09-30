<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Account;

use Doctrine\DBAL\Connection;

/**
 * Does another account already carry this display name?
 *
 * Information only. Display names are labels, not identifiers, and two riders
 * may share one; this answers a rider who would rather have a name of their
 * own. Case and edge spaces do not count: "Jan Peeters" and " jan peeters"
 * are the same name to a reader.
 *
 * The answer is one boolean, never a name, an id or a count, and the query
 * reads only `display_name`: an address can never be looked up through it.
 *
 * Only public profiles count (owner 2026-09-30). Their names are already on
 * pins and the contributors wall, so the answer tells a visitor nothing the
 * site does not show; a private rider's name is never confirmed to anyone.
 *
 * @see docs/specs/account-and-auth.md §9
 *
 * @api
 */
final readonly class DisplayNameCheck
{
    /** The forms' floor and the entity's ceiling (RegistrationFormType, User). */
    public const int MIN_LENGTH = 2;
    public const int MAX_LENGTH = 100;

    public function __construct(private Connection $db)
    {
    }

    /** A name the forms could accept by length, so worth asking about. */
    public static function askable(string $name): bool
    {
        $length = mb_strlen(trim($name));

        return $length >= self::MIN_LENGTH && $length <= self::MAX_LENGTH;
    }

    /**
     * True when a public profile other than $exceptUserId uses the name.
     *
     * A count over the whole table rather than EXISTS, so the query costs the
     * same whichever way the answer goes.
     */
    public function inUse(string $name, ?int $exceptUserId = null): bool
    {
        $count = $this->db->fetchOne(
            'SELECT COUNT(*) FROM users
              WHERE LOWER(BTRIM(display_name)) = LOWER(:name)
                AND public_profile
                AND id <> :except',
            ['name' => trim($name), 'except' => $exceptUserId ?? 0],
        );

        return (int) $count > 0;
    }
}
