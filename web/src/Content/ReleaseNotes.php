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
        // Refreshed 2026-09-25 (owner): the big things only. Smaller pieces of
        // work are private known issues on the curator desk; the order within
        // a group is not a promise.
        ['key' => 'roadmap.item_going_live', 'status' => 'now'],
        ['key' => 'roadmap.item_provider_refresh', 'status' => 'now'],
        ['key' => 'roadmap.item_roadmap_votes', 'status' => 'next'],
        ['key' => 'roadmap.item_region_vote', 'status' => 'next'],
        ['key' => 'roadmap.item_more_countries', 'status' => 'next'],
        ['key' => 'roadmap.item_scout_devices', 'status' => 'next'],
        ['key' => 'roadmap.item_route_export', 'status' => 'next'],
        ['key' => 'roadmap.item_open_api', 'status' => 'next'],
        ['key' => 'roadmap.item_osm_giveback', 'status' => 'later'],
        ['key' => 'roadmap.item_region_portrait', 'status' => 'later'],
        ['key' => 'roadmap.item_reviews', 'status' => 'later'],
        ['key' => 'roadmap.item_heatmap', 'status' => 'later'],
        ['key' => 'roadmap.item_standing', 'status' => 'later'],
        ['key' => 'roadmap.item_map_a11y', 'status' => 'later'],
    ];

    /** The three groups, in display order. */
    public const array STATUSES = ['now', 'next', 'later'];

    /**
     * The groups a release's notes sit in, in display order, from 0.9.4-beta
     * on (owner 2026-10-04): what everyone sees on the site, what a rider's
     * account gains, and what curators gain. Each has its heading,
     * `changelog.section_<name>`. An older release keeps its flat `keys`.
     */
    public const array SECTIONS = ['public', 'rider', 'curator'];

    /**
     * Released versions, newest first.
     *
     * `version` is the git tag without the `v`, so the footer stamp and this
     * page agree: `BuildVersion` runs `git describe --tags --match 'v*'`, which
     * means tagging `v0.8.0-beta` is what makes both say the same thing.
     * `date` is the release date, ISO, and it is also the feed's timestamp.
     * `sections` holds the notes by {@see self::SECTIONS}; a release from
     * before sections has `keys`, one list without a heading.
     *
     * @var list<array{version: string, date: string, keys?: list<string>, sections?: array<string, list<string>>}>
     */
    public const array RELEASES = [
        [
            'version' => '0.9.4-beta',
            'date' => '2026-10-04',
            'sections' => [
                'public' => [
                    'changelog.v094_ballot',
                    'changelog.v094_best',
                    'changelog.v094_fair',
                    'changelog.v094_climbs',
                    'changelog.v094_map',
                    'changelog.v094_fixes',
                ],
                'rider' => [
                    'changelog.v094_name',
                    'changelog.v094_confirmations',
                ],
                'curator' => [
                    'changelog.v094_bugs',
                    'changelog.v094_providers',
                    'changelog.v094_desk',
                ],
            ],
        ],
        [
            'version' => '0.9.3-beta',
            'date' => '2026-10-01',
            'keys' => [
                'changelog.v093_climbs',
                'changelog.v093_texts',
                'changelog.v093_contributions',
                'changelog.v093_signin',
                'changelog.v093_updates',
                'changelog.v093_privacy',
                'changelog.v093_messages',
                'changelog.v093_access',
                'changelog.v093_fixes',
                'changelog.v093_curators',
            ],
        ],
        [
            'version' => '0.9.2-beta',
            'date' => '2026-09-29',
            'keys' => [
                'changelog.v092_area',
                'changelog.v092_search',
                'changelog.v092_fresh',
                'changelog.v092_key',
                'changelog.v092_similar',
                'changelog.v092_bugs',
                'changelog.v092_speed',
                'changelog.v092_confirm',
                'changelog.v092_strava',
            ],
        ],
        [
            'version' => '0.9.1-beta',
            'date' => '2026-09-27',
            'keys' => [
                'changelog.v091_banner',
                'changelog.v091_climbs',
                'changelog.v091_languages',
                'changelog.v091_reset',
                'changelog.v091_bugs',
                'changelog.v091_safety',
            ],
        ],
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
     * Every release with its notes grouped, newest first. A release from
     * before sections is one group without a heading (`''`); a sectioned
     * one lists its groups in {@see self::SECTIONS} order and leaves out an
     * empty one.
     *
     * @return list<array{version: string, date: string, sections: array<string, list<string>>}>
     */
    public static function releases(): array
    {
        return array_map(self::grouped(...), self::RELEASES);
    }

    /**
     * One release with its notes grouped. A parameter rather than a loop over
     * the constant, so static analysis reads the declared shape and not the
     * entries the list happens to hold today.
     *
     * @param array{version: string, date: string, keys?: list<string>, sections?: array<string, list<string>>} $release
     *
     * @return array{version: string, date: string, sections: array<string, list<string>>}
     */
    private static function grouped(array $release): array
    {
        $sections = [];
        if (!isset($release['sections'])) {
            $sections[''] = $release['keys'] ?? [];
        } else {
            foreach (self::SECTIONS as $name) {
                if ([] !== ($release['sections'][$name] ?? [])) {
                    $sections[$name] = $release['sections'][$name];
                }
            }
        }

        return ['version' => $release['version'], 'date' => $release['date'], 'sections' => $sections];
    }

    /**
     * The newest release.
     *
     * Static analysis can see that `RELEASES` is never empty and so reads any
     * null branch here as dead code. It is dead only for as long as the
     * constant has an entry in it, which is why the caller still treats the
     * result as optional and the template guards on it.
     *
     * @return array{version: string, date: string, keys?: list<string>, sections?: array<string, list<string>>}
     */
    public static function latestRelease(): array
    {
        return self::RELEASES[0];
    }
}
