<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\LicenceUrls;
use PHPUnit\Framework\TestCase;

/**
 * Every licence we accept must be one the drawer can link to its own deed.
 *
 * The list lives twice by necessity: `CommonsApi::FREE_LICENCES` is the gate
 * that decides whether a file may be copied into our bucket at all, and
 * `ccUrl()` in web/assets/map/util.js turns the licence name into the URL under
 * the photo. Both files already say in prose that they must agree. Nothing
 * made them.
 *
 * The failure is quiet and it is a licence failure, not a cosmetic one. Add a
 * licence to the PHP gate alone and we start republishing files under it while
 * the caption links to the generic Commons:Licensing index instead of the deed
 * that actually governs the photo. CC BY-SA asks for the licence to be
 * identified; a page listing every licence Commons has ever seen does not
 * identify one. The photo still appears, the credit still appears, and nobody
 * notices.
 *
 * Only one direction is checked. `ccUrl()` may know more licences than the gate
 * accepts, because it also captions rider uploads, and a name it cannot place
 * already falls back safely.
 *
 * @see docs/specs/photo-uploads.md §5f
 */
final class FreeLicenceLinkabilityTest extends TestCase
{
    private const string CC_URL_JS = __DIR__.'/../../assets/map/util.js';

    public function testEveryAcceptedLicenceHasItsOwnDeedUrl(): void
    {
        $linkable = self::ccUrlTable();

        self::assertNotEmpty($linkable, 'ccUrl() was read but no licence names came out: the parser, not the code, is wrong');

        foreach (LicenceUrls::URLS as $licence => $deed) {
            self::assertArrayHasKey(
                $licence,
                $linkable,
                sprintf(
                    'LicenceUrls accepts "%s" but ccUrl() in assets/map/util.js cannot link it, so the caption '
                    .'would point at the generic Commons licensing index instead of that licence. Add it to '
                    .'ccUrl(), or stop accepting it.',
                    $licence,
                ),
            );
            self::assertSame(
                $deed,
                $linkable[$licence],
                sprintf('"%s" points at a different deed in PHP and in the browser', $licence),
            );
        }
    }

    /**
     * The names ccUrl() maps, read from the file rather than restated here.
     *
     * A second copy in this test would be one more thing to keep in step, which
     * is the exact problem being tested.
     *
     * @return array<string, string>
     */
    private static function ccUrlTable(): array
    {
        $source = file_get_contents(self::CC_URL_JS);
        self::assertIsString($source, self::CC_URL_JS.' is unreadable');

        $start = strpos($source, 'export const ccUrl');
        self::assertNotFalse($start, 'ccUrl() has moved or been renamed in assets/map/util.js');
        $end = strpos($source, '}[lic]', $start);
        self::assertNotFalse($end, 'ccUrl() no longer ends in the fallback lookup this test parses');

        preg_match_all(
            "~'([^']+)'\s*:\s*'(https://[^']+)'~",
            substr($source, $start, $end - $start),
            $matches,
            \PREG_SET_ORDER,
        );

        $table = [];
        foreach ($matches as $m) {
            $table[$m[1]] = $m[2];
        }

        return $table;
    }
}
