<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

/**
 * Which part of the site a bug is in.
 *
 * A fixed list, not a free-text tag and not a curator-managed table. A rider
 * reporting a bug should be choosing from a handful of words they already
 * recognise from the navigation, and a list that curators can grow ends up with
 * "Map", "map" and "Maps" in it inside a month.
 *
 * The reporter picks one; a curator can move it. Unsure is a real answer and
 * sits last so it is never the accidental default.
 *
 * @see docs/specs/contact-and-support.md §5
 *
 * @api
 */
enum BugArea: string
{
    case Map = 'map';
    case Search = 'search';
    case Contributing = 'contributing';
    case Photos = 'photos';
    case Routes = 'routes';
    case Account = 'account';
    case Regions = 'regions';
    case Translations = 'translations';
    case Pages = 'pages';
    case Unsure = 'unsure';

    /** @return list<self> form order; Unsure last, never the default */
    public static function all(): array
    {
        return [
            self::Map,
            self::Search,
            self::Contributing,
            self::Photos,
            self::Routes,
            self::Account,
            self::Regions,
            self::Translations,
            self::Pages,
            self::Unsure,
        ];
    }

    public static function fromInput(string $value): self
    {
        return self::tryFrom($value) ?? self::Unsure;
    }

    /**
     * The area a path most likely belongs to, for pre-filling the form.
     *
     * A guess, and the rider can always change it. Worth making because the
     * alternative is that every report arrives tagged Unsure. The form
     * captures the page URL anyway, so we may as well read it.
     */
    public static function guessFromPath(string $path): self
    {
        // The locale prefix is not part of what the path is about.
        $path = (string) preg_replace('~^/(fr|nl|de|es)(?=/|$)~', '', $path);

        return match (true) {
            str_starts_with($path, '/map') => self::Map,
            str_starts_with($path, '/photo'), str_starts_with($path, '/media') => self::Photos,
            str_starts_with($path, '/routes'), str_starts_with($path, '/propose-route') => self::Routes,
            str_starts_with($path, '/contribute'), str_starts_with($path, '/improve') => self::Contributing,
            str_starts_with($path, '/profile'), str_starts_with($path, '/settings'),
            str_starts_with($path, '/login'), str_starts_with($path, '/register'),
            str_starts_with($path, '/reset-password'), str_starts_with($path, '/2fa') => self::Account,
            str_starts_with($path, '/regions'), str_starts_with($path, '/region') => self::Regions,
            str_starts_with($path, '/translate') => self::Translations,
            default => self::Unsure,
        };
    }
}
