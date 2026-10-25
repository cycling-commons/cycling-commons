<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * Who counts as looking after a region on the public pages: the curators
 * scoped to it or to its country (`moderator_area`), never an admin. An
 * admin moderates every area with or without a row there, so their rows
 * would make every country read as covered while nobody else looks after
 * it, and hide the call for curators (map-and-search.md §4.5).
 *
 * Moderation itself is untouched: the queue, the desks and the scopes still
 * read every row (ModerationScopeProvider).
 *
 * @api
 */
final class RegionCover
{
    /** An area row held by an account that is not an admin; `$area` is the moderator_area alias. */
    public static function counted(string $area): string
    {
        return sprintf(
            "EXISTS (SELECT 1 FROM users cover_u WHERE cover_u.id = %s.user_id AND NOT (cover_u.roles::jsonb @> '[\"ROLE_ADMIN\"]'::jsonb))",
            $area,
        );
    }

    /** Somebody counted looks after this region itself; `$region` is the region alias. */
    public static function localSql(string $region): string
    {
        return sprintf('EXISTS (SELECT 1 FROM moderator_area cover_ma WHERE cover_ma.region_id = %s.id AND %s)', $region, self::counted('cover_ma'));
    }

    /** Somebody counted looks after the whole country of this region; `$region` is the region alias. */
    public static function nationalSql(string $region): string
    {
        return sprintf('EXISTS (SELECT 1 FROM moderator_area cover_ma WHERE cover_ma.country_code = %s.country_code AND %s)', $region, self::counted('cover_ma'));
    }
}
