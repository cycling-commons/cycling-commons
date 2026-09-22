<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Content;

/**
 * The roadmap and the changelog, from one list.
 *
 * Owner's brief, 2026-08-28: "for now it must be a very simple list", and
 * "combine roadmap with github issues or something smart and simple". This is
 * the simple version, and the simplicity is the point:
 *
 * - **One file, no table, no admin screen.** Editing the roadmap is a pull
 *   request, which is the same review the rest of the site gets and leaves a
 *   history for free. A database-backed roadmap with a curator queue is
 *   `docs/TODO.md` 7's destination, not its first version.
 * - **Text lives in the catalogue like all other copy.** Every entry is a
 *   translation key, so the page reads in five languages the day it ships
 *   rather than being an English island.
 * - **The GitHub link is a number, not a sync.** An item may name an issue;
 *   the page links it. Nothing polls GitHub, nothing breaks when GitHub is
 *   down, and an item without an issue is still a valid item.
 * - **Shipped is not repeated.** The roadmap shows what is coming; what landed
 *   is the changelog, and the roadmap links to it. One fact, one place.
 *
 * @see docs/specs/roadmap-and-changelog.md
 *
 * @api
 */
final class ReleaseNotes
{
    /**
     * What is coming, in the order a reader should meet it.
     *
     * `now` is being worked on, `next` is decided and waiting, `later` is
     * wanted and unscheduled. Anything finished leaves this list and appears in
     * {@see self::RELEASES}; an item that is neither is not on the roadmap.
     *
     * @var list<array{key: string, status: string, issue?: int}>
     */
    public const array ROADMAP = [
        // Refreshed 2026-09-06 from docs/TODO.md: features only, no bugs, no
        // deploy chores; anything that shipped left for the changelog.
        ['key' => 'roadmap.item_curator_direct', 'status' => 'now'],
        ['key' => 'roadmap.item_pending_notice', 'status' => 'next'],
        ['key' => 'roadmap.item_provider_refresh', 'status' => 'next'],
        ['key' => 'roadmap.item_release_mail', 'status' => 'next'],
        ['key' => 'roadmap.item_support_thread', 'status' => 'next'],
        ['key' => 'roadmap.item_surface_loop', 'status' => 'next'],
        ['key' => 'roadmap.item_photo_link', 'status' => 'next'],
        ['key' => 'roadmap.item_phone_map', 'status' => 'next'],
        ['key' => 'roadmap.item_region_vote', 'status' => 'later'],
        ['key' => 'roadmap.item_heatmap', 'status' => 'later'],
        ['key' => 'roadmap.item_saved_regions', 'status' => 'later'],
        ['key' => 'roadmap.item_observations', 'status' => 'later'],
        ['key' => 'roadmap.item_overtakes', 'status' => 'later'],
        ['key' => 'roadmap.item_item_links', 'status' => 'later'],
        ['key' => 'roadmap.item_standing', 'status' => 'later'],
        ['key' => 'roadmap.item_curator_room', 'status' => 'later'],
        ['key' => 'roadmap.item_beta', 'status' => 'later'],
        ['key' => 'roadmap.item_osm_giveback', 'status' => 'later'],
        ['key' => 'roadmap.item_map_a11y', 'status' => 'later'],
        ['key' => 'roadmap.item_route_export', 'status' => 'later'],
        ['key' => 'roadmap.item_contraflow', 'status' => 'later'],
        ['key' => 'roadmap.item_region_portrait', 'status' => 'later'],
        ['key' => 'roadmap.item_reviews', 'status' => 'later'],
        ['key' => 'roadmap.item_roadmap_votes', 'status' => 'later'],
    ];

    /** The three groups, in display order. */
    public const array STATUSES = ['now', 'next', 'later'];

    /**
     * Released versions, newest first.
     *
     * `version` is the git tag without the `v`, so the footer stamp and this
     * page agree: `BuildVersion` runs `git describe --tags --match 'v*'`, which
     * means tagging `v0.8.0-beta` is what makes both say the same thing.
     * `date` is the release date, ISO, and it is also the feed's timestamp.
     *
     * @var list<array{version: string, date: string, keys: list<string>}>
     */
    public const array RELEASES = [
        [
            'version' => '0.9.0-beta',
            'date' => '2026-09-22',
            'keys' => [
                'changelog.v090_towns',
                'changelog.v090_fresh',
                'changelog.v090_surface',
                'changelog.v090_photos',
                'changelog.v090_accounts',
            ],
        ],
        [
            'version' => '0.8.0-beta',
            'date' => '2026-08-28',
            'keys' => [
                'changelog.v080_map',
                'changelog.v080_photos',
                'changelog.v080_routes',
                'changelog.v080_contribute',
                'changelog.v080_accounts',
                'changelog.v080_languages',
                'changelog.v080_legal',
            ],
        ],
    ];

    /**
     * @return list<array{key: string, status: string, issue?: int}>
     */
    public static function roadmapFor(string $status): array
    {
        return array_values(array_filter(
            self::ROADMAP,
            static fn (array $item): bool => $item['status'] === $status,
        ));
    }

    /**
     * The newest release.
     *
     * Static analysis can see that `RELEASES` is never empty and so reads any
     * null branch here as dead code. It is dead only for as long as the
     * constant has an entry in it, which is why the caller still treats the
     * result as optional and the template guards on it.
     *
     * @return array{version: string, date: string, keys: list<string>}
     */
    public static function latestRelease(): array
    {
        return self::RELEASES[0];
    }
}
