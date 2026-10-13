<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Content;

/**
 * The changelog: every release and its notes, and the three groups the
 * roadmap uses.
 *
 * - **One file.** A release's notes are written with the release, by pull
 *   request, so they get the same review as the rest of the site and keep a
 *   history for free.
 * - **Text lives in the catalogue like all other copy.** Every note is a
 *   translation key, so the page reads in five languages the day it ships.
 * - **Shipped is not repeated.** The roadmap (in the database, App\Roadmap)
 *   shows what is coming; what landed is here, and the roadmap links to it.
 *
 * @see docs/specs/roadmap-and-changelog.md
 *
 * @api
 */
final class ReleaseNotes
{
    /** The roadmap's three groups, in display order. */
    public const array STATUSES = ['now', 'next', 'later'];

    /**
     * The groups a release's notes sit in, in display order, from 0.9.4-beta
     * on (owner 2026-10-04): what everyone sees on the site, what a rider's
     * account gains, and what curators gain. Each has its heading,
     * `changelog.section_<name>`. An older release keeps its flat `keys`.
     */
    public const array SECTIONS = ['public', 'rider', 'curator'];

    /**
     * What kind of change a note is, shown as a small label beside it
     * (owner 2026-10-13): something that was not there before, something that
     * works better, or something that was broken.
     */
    public const array KINDS = ['new', 'improved', 'fixed'];

    /**
     * Released versions, newest first.
     *
     * `version` is the git tag without the `v`, so the footer stamp and this
     * page agree: `BuildVersion` runs `git describe --tags --match 'v*'`, which
     * means tagging `v0.8.0-beta` is what makes both say the same thing.
     * `date` is the release date, ISO, and it is also the feed's timestamp.
     * `sections` holds the notes by {@see self::SECTIONS}; a release from
     * before sections has `keys`, one list without a heading. `kinds`
     * names each note's kind ({@see self::KINDS}).
     *
     * @var list<array{version: string, date: string, keys?: list<string>, sections?: array<string, list<string>>, kinds?: array<string, string>}>
     */
    public const array RELEASES = [
        [
            'version' => '0.9.7-beta',
            'date' => '2026-10-13',
            'sections' => [
                'public' => [
                    'changelog.v097_roadmap',
                    'changelog.v097_karoo',
                    'changelog.v097_privacy',
                    'changelog.v097_fixes',
                ],
                'rider' => [
                    'changelog.v097_proposals',
                    'changelog.v097_mail_why',
                ],
                'curator' => [
                    'changelog.v097_curator_proposals',
                ],
            ],
            'kinds' => [
                'changelog.v097_roadmap' => 'new',
                'changelog.v097_karoo' => 'new',
                'changelog.v097_privacy' => 'improved',
                'changelog.v097_fixes' => 'fixed',
                'changelog.v097_proposals' => 'new',
                'changelog.v097_mail_why' => 'fixed',
                'changelog.v097_curator_proposals' => 'new',
            ],
        ],
        [
            'version' => '0.9.6-beta',
            'date' => '2026-10-11',
            'sections' => [
                'public' => [
                    'changelog.v096_denmark',
                    'changelog.v096_scout',
                    'changelog.v096_tools',
                    'changelog.v096_next',
                    'changelog.v096_privacy',
                    'changelog.v096_security',
                    'changelog.v096_fixes',
                ],
                'rider' => [
                    'changelog.v096_country_live',
                ],
                'curator' => [
                    'changelog.v096_curator_areas',
                    'changelog.v096_takedowns',
                ],
            ],
            'kinds' => [
                'changelog.v096_denmark' => 'new',
                'changelog.v096_scout' => 'new',
                'changelog.v096_tools' => 'improved',
                'changelog.v096_next' => 'new',
                'changelog.v096_privacy' => 'improved',
                'changelog.v096_security' => 'improved',
                'changelog.v096_fixes' => 'fixed',
                'changelog.v096_country_live' => 'new',
                'changelog.v096_curator_areas' => 'improved',
                'changelog.v096_takedowns' => 'improved',
            ],
        ],
        [
            'version' => '0.9.5-beta',
            'date' => '2026-10-10',
            'sections' => [
                'public' => [
                    'changelog.v095_kinds',
                    'changelog.v095_map',
                    'changelog.v095_search',
                    'changelog.v095_privacy',
                    'changelog.v095_terms',
                    'changelog.v095_reasons',
                    'changelog.v095_export',
                    'changelog.v095_security',
                    'changelog.v095_fixes',
                ],
                'rider' => [
                    'changelog.v095_traffic',
                    'changelog.v095_rides',
                    'changelog.v095_scout_tags',
                    'changelog.v095_time_zone',
                    'changelog.v095_base',
                    'changelog.v095_hints',
                ],
                'curator' => [
                    'changelog.v095_traffic_desk',
                    'changelog.v095_curator_kinds',
                    'changelog.v095_grant',
                ],
            ],
            'kinds' => [
                'changelog.v095_kinds' => 'new',
                'changelog.v095_map' => 'improved',
                'changelog.v095_search' => 'improved',
                'changelog.v095_privacy' => 'improved',
                'changelog.v095_terms' => 'improved',
                'changelog.v095_reasons' => 'new',
                'changelog.v095_export' => 'new',
                'changelog.v095_security' => 'improved',
                'changelog.v095_fixes' => 'fixed',
                'changelog.v095_traffic' => 'new',
                'changelog.v095_rides' => 'new',
                'changelog.v095_scout_tags' => 'fixed',
                'changelog.v095_time_zone' => 'new',
                'changelog.v095_base' => 'improved',
                'changelog.v095_hints' => 'improved',
                'changelog.v095_traffic_desk' => 'new',
                'changelog.v095_curator_kinds' => 'improved',
                'changelog.v095_grant' => 'improved',
            ],
        ],
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
                    'changelog.v094_security',
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
            'kinds' => [
                'changelog.v094_ballot' => 'new',
                'changelog.v094_best' => 'new',
                'changelog.v094_fair' => 'new',
                'changelog.v094_climbs' => 'new',
                'changelog.v094_map' => 'improved',
                'changelog.v094_security' => 'improved',
                'changelog.v094_fixes' => 'fixed',
                'changelog.v094_name' => 'new',
                'changelog.v094_confirmations' => 'improved',
                'changelog.v094_bugs' => 'improved',
                'changelog.v094_providers' => 'new',
                'changelog.v094_desk' => 'improved',
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
            'kinds' => [
                'changelog.v093_climbs' => 'improved',
                'changelog.v093_texts' => 'new',
                'changelog.v093_contributions' => 'improved',
                'changelog.v093_signin' => 'improved',
                'changelog.v093_updates' => 'new',
                'changelog.v093_privacy' => 'improved',
                'changelog.v093_messages' => 'improved',
                'changelog.v093_access' => 'improved',
                'changelog.v093_fixes' => 'fixed',
                'changelog.v093_curators' => 'improved',
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
            'kinds' => [
                'changelog.v092_area' => 'improved',
                'changelog.v092_search' => 'improved',
                'changelog.v092_fresh' => 'new',
                'changelog.v092_key' => 'improved',
                'changelog.v092_similar' => 'new',
                'changelog.v092_bugs' => 'improved',
                'changelog.v092_speed' => 'improved',
                'changelog.v092_confirm' => 'improved',
                'changelog.v092_strava' => 'new',
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
            'kinds' => [
                'changelog.v091_banner' => 'new',
                'changelog.v091_climbs' => 'new',
                'changelog.v091_languages' => 'improved',
                'changelog.v091_reset' => 'improved',
                'changelog.v091_bugs' => 'improved',
                'changelog.v091_safety' => 'improved',
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
            'kinds' => [
                'changelog.v090_towns' => 'new',
                'changelog.v090_fresh' => 'improved',
                'changelog.v090_surface' => 'improved',
                'changelog.v090_photos' => 'improved',
                'changelog.v090_accounts' => 'improved',
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
            'kinds' => [
                'changelog.v080_map' => 'new',
                'changelog.v080_photos' => 'new',
                'changelog.v080_routes' => 'new',
                'changelog.v080_contribute' => 'new',
                'changelog.v080_accounts' => 'new',
                'changelog.v080_languages' => 'new',
                'changelog.v080_legal' => 'new',
            ],
        ],
    ];

    /**
     * Every release with its notes grouped, newest first. A release from
     * before sections is one group without a heading (`''`); a sectioned
     * one lists its groups in {@see self::SECTIONS} order and leaves out an
     * empty one.
     *
     * @return list<array{version: string, date: string, sections: array<string, list<string>>, kinds: array<string, string>}>
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
     * @param array{version: string, date: string, keys?: list<string>, sections?: array<string, list<string>>, kinds?: array<string, string>} $release
     *
     * @return array{version: string, date: string, sections: array<string, list<string>>, kinds: array<string, string>}
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

        return ['version' => $release['version'], 'date' => $release['date'], 'sections' => $sections, 'kinds' => $release['kinds'] ?? []];
    }

    /**
     * The newest release.
     *
     * Static analysis can see that `RELEASES` is never empty and so reads any
     * null branch here as dead code. It is dead only for as long as the
     * constant has an entry in it, which is why the caller still treats the
     * result as optional and the template guards on it.
     *
     * @return array{version: string, date: string, keys?: list<string>, sections?: array<string, list<string>>, kinds?: array<string, string>}
     */
    public static function latestRelease(): array
    {
        return self::RELEASES[0];
    }
}
