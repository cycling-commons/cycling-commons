<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\RiderPseudonym;
use App\Entity\User;

/**
 * How a curator desk names a rider: by display name, linked to their rider
 * profile, when the rider made that profile public; otherwise by the stable
 * pseudonym with no link. Every desk card and review page asks this one class,
 * and templates/moderate/_rider_name.html.twig writes the answer.
 *
 * A fellow curator (who decided, who posted in the room) is named by display
 * name whatever their profile setting, because curators are named to each
 * other on the desks; the link to their profile follows the same public
 * setting (`colleague()`).
 *
 * @see docs/specs/moderation-and-contribution.md (curator-facing naming)
 *
 * @api
 */
final class DeskRider
{
    /**
     * @return array{name:string, uuid:?string} `uuid` is set only when the
     *                                          name may link to `rider_profile`
     */
    public static function of(int|string $userId, mixed $displayName, mixed $publicProfile, mixed $uuid): array
    {
        $name = \is_string($displayName) ? trim($displayName) : '';
        if (\in_array($publicProfile, [true, 1, '1', 't', 'true'], true) && '' !== $name) {
            $profile = \is_string($uuid) || $uuid instanceof \Stringable ? (string) $uuid : '';

            return ['name' => $name, 'uuid' => '' !== $profile ? $profile : null];
        }

        return ['name' => RiderPseudonym::for($userId), 'uuid' => null];
    }

    /**
     * @return array{name:string, uuid:?string}
     */
    public static function ofUser(User $user): array
    {
        return self::of((int) $user->getId(), $user->getDisplayName(), $user->isPublicProfile(), $user->getUuid()?->toRfc4122());
    }

    /**
     * A fellow curator: always their display name; linked only when their
     * profile is public. Null for an account with no name (a removed one).
     *
     * @return array{name:string, uuid:?string}|null
     */
    public static function colleague(mixed $displayName, mixed $publicProfile, mixed $uuid): ?array
    {
        $name = \is_string($displayName) ? trim($displayName) : '';
        if ('' === $name) {
            return null;
        }
        $profile = \is_string($uuid) || $uuid instanceof \Stringable ? (string) $uuid : '';
        $public = \in_array($publicProfile, [true, 1, '1', 't', 'true'], true);

        return ['name' => $name, 'uuid' => $public && '' !== $profile ? $profile : null];
    }

    /**
     * @return array{name:string, uuid:?string}|null
     */
    public static function colleagueUser(User $user): ?array
    {
        return self::colleague($user->getDisplayName(), $user->isPublicProfile(), $user->getUuid()?->toRfc4122());
    }
}
