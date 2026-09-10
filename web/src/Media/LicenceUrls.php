<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

/**
 * Licence name to the deed that governs it. The one list, in PHP.
 *
 * It is needed in three places and used to exist in three places. The gate that
 * decides whether a Commons file may be copied at all reads the names
 * ({@see Commons\CommonsApi::FREE_LICENCES}); the XMP packet written into the
 * stored file needs the URL ({@see XmpRights::forCommonsFile()}); and the
 * caption under the photo needs the URL again, in the browser
 * (`ccUrl()` in assets/map/util.js).
 *
 * The browser copy cannot be removed, so it is pinned instead:
 * `tests/Media/FreeLicenceLinkabilityTest.php` reads util.js and fails when the
 * two disagree. The failure it exists to prevent is quiet and it is a licence
 * failure: a name known to one side and not the other means we republish a file
 * while pointing at the wrong deed, or at the generic Commons licensing index,
 * which lists every licence and therefore identifies none.
 *
 * "Public domain" is not a licence and has no deed, so it points at the
 * explainer, which is what a reader following the link actually wants.
 *
 * Commons' bare "Attribution" is deliberately absent. It is a template, not a
 * licence: it means the uploader asked for credit on terms stated in prose
 * somewhere on the file page, and there is no deed to point a reader at. A
 * file carrying it is refused, because a licence we cannot identify is one we
 * cannot attribute.
 *
 * @see docs/specs/photo-uploads.md §5f
 *
 * @api
 */
final class LicenceUrls
{
    /** Commons' own `LicenseShortName` strings, which is what we store. */
    public const array URLS = [
        'CC0' => 'https://creativecommons.org/publicdomain/zero/1.0/',
        'Public domain' => 'https://en.wikipedia.org/wiki/Public_domain',
        'CC BY 4.0' => 'https://creativecommons.org/licenses/by/4.0/',
        'CC BY 3.0' => 'https://creativecommons.org/licenses/by/3.0/',
        'CC BY 2.5' => 'https://creativecommons.org/licenses/by/2.5/',
        'CC BY 2.0' => 'https://creativecommons.org/licenses/by/2.0/',
        'CC BY-SA 4.0' => 'https://creativecommons.org/licenses/by-sa/4.0/',
        'CC BY-SA 3.0' => 'https://creativecommons.org/licenses/by-sa/3.0/',
        'CC BY-SA 3.0 lu' => 'https://creativecommons.org/licenses/by-sa/3.0/lu/',
        'CC BY-SA 2.5' => 'https://creativecommons.org/licenses/by-sa/2.5/',
        'CC BY-SA 2.0' => 'https://creativecommons.org/licenses/by-sa/2.0/',
        // Jurisdiction ports of BY-SA 2.0, and common on older Belgian and
        // German uploads: nine photos in the first real backfill were refused
        // for carrying one, all of them freely licensed. A port is the same
        // licence under a local legal system, so it is accepted on the same
        // terms and linked to its own deed rather than the unported one.
        'CC BY-SA 2.0 be' => 'https://creativecommons.org/licenses/by-sa/2.0/be/',
        'CC BY-SA 2.0 de' => 'https://creativecommons.org/licenses/by-sa/2.0/de/',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::URLS);
    }

    /** The deed for a licence we accept, or null for a name we do not know. */
    public static function urlFor(string $licence): ?string
    {
        return self::URLS[$licence] ?? null;
    }
}
