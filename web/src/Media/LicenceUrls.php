<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

/**
 * Licence name to the deed that governs it. The one list.
 *
 * The list itself lives in `licences.json` beside this class, because two
 * languages read it: PHP here, and the Python harvest tools
 * (`tools/wikimedia/commons_photo.py`, which the Wallonia enrichment calls).
 * One file means a name accepted by a harvest is a name the app accepts.
 *
 * Three things in PHP need it. {@see PhotoValidator} refuses a photo whose
 * licence is not on it; the XMP packet written into a stored Commons file needs
 * the URL ({@see XmpRights::forCommonsFile()}); and the caption under the photo
 * needs the URL again, in the browser (`ccUrl()` in assets/map/util.js).
 *
 * The browser copy cannot be removed, so it is pinned instead:
 * `tests/Media/FreeLicenceLinkabilityTest.php` reads util.js and fails when the
 * two disagree. The failure it exists to prevent is quiet and it is a licence
 * failure: a name known to one side and not the other means we republish a file
 * while pointing at the wrong deed, or at the generic Commons licensing index,
 * which lists every licence and therefore identifies none.
 *
 * Names are Commons' own `LicenseShortName` strings, which is what we store.
 *
 * "Public domain" is not a licence and has no deed, so it points at the
 * explainer, which is what a reader following the link actually wants.
 *
 * The jurisdiction ports of BY-SA 2.0 (`be`, `de`) and BY-SA 3.0 (`lu`) are on
 * the list because older Belgian, German and Luxembourgish uploads carry them.
 * A port is the same licence under a local legal system, so it is accepted on
 * the same terms and linked to its own deed rather than the unported one.
 *
 * Commons' bare "Attribution" is absent. It is a template, not a licence: it
 * means the uploader asked for credit on terms stated in prose somewhere on the
 * file page, and there is no deed to point a reader at. A file carrying it is
 * refused, because a licence we cannot identify is one we cannot attribute.
 *
 * @see docs/specs/photo-uploads.md §5f
 *
 * @api
 */
final class LicenceUrls
{
    /** The list both PHP and the Python harvest tools read. */
    public const string FILE = __DIR__.'/licences.json';

    /** @var array<string, string>|null */
    private static ?array $urls = null;

    /**
     * Every accepted licence name, mapped to its deed.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        if (null === self::$urls) {
            $decoded = json_decode((string) file_get_contents(self::FILE), true, 4, \JSON_THROW_ON_ERROR);
            $urls = [];
            foreach (\is_array($decoded) ? $decoded : [] as $name => $url) {
                if (\is_string($name) && \is_string($url)) {
                    $urls[$name] = $url;
                }
            }
            self::$urls = $urls;
        }

        return self::$urls;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    /** The deed for a licence we accept, or null for a name we do not know. */
    public static function urlFor(string $licence): ?string
    {
        return self::all()[$licence] ?? null;
    }
}
