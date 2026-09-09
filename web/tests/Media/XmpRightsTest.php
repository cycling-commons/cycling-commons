<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\XmpRights;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The authored rights packet (docs/specs/photo-uploads.md §1.3c). The negative
 * assertion here is the important one: no display name may ever be embedded in
 * a stored file, because a name in a downloaded file cannot be withdrawn and
 * display names are neither unique over time nor verified. Attribution is a
 * UUID link whose target we control.
 */
final class XmpRightsTest extends TestCase
{
    private const string MEDIA_UUID = '0198c0de-0000-7000-8000-000000000001';

    private static function packet(string $siteUrl = 'https://cyclingcommons.example'): string
    {
        return (new XmpRights($siteUrl))->forPhoto(Uuid::fromString(self::MEDIA_UUID));
    }

    /**
     * A Commons file is somebody else's work, and its packet has to say so.
     *
     * The rider packet points at OUR photo page and names OUR licence, which is
     * right for a rider's own photo and wrong for every other kind. Reusing it
     * here would put our attribution URL on a stranger's picture: a reader who
     * followed the file's own metadata would be told the wrong author, the
     * wrong licence and the wrong place to look.
     *
     * The re-encode strips whatever XMP the file arrived with, so leaving this
     * out is not neutral either: it publishes an orphan, with no author and no
     * licence in the bytes at all. CC BY-SA asks for attribution to travel with
     * the work, and a caption on one HTML page is not the work travelling.
     */
    public function testACommonsFileCarriesItsOwnAuthorLicenceAndSource(): void
    {
        $packet = (new XmpRights('https://cyclingcommons.example'))->forCommonsFile(
            'LBL 2008 Côte de Wanne.jpg',
            'Les Meloures at lb.wikipedia',
            'Les Meloures',
            'CC BY-SA 3.0 lu',
        );

        // Their licence, not ours.
        self::assertStringContainsString('CC BY-SA 3.0 lu', $packet);
        self::assertStringContainsString('https://creativecommons.org/licenses/by-sa/3.0/lu/', $packet);
        self::assertStringNotContainsString(XmpRights::LICENSE_URL, $packet, 'our own default licence must not be asserted over theirs');

        // Their name and their user page.
        self::assertStringContainsString('Les Meloures at lb.wikipedia', $packet);
        self::assertStringContainsString('https://commons.wikimedia.org/wiki/User:Les_Meloures', $packet);

        // Attribution points at the Commons file page, where the full statement
        // lives, and never at a page of ours.
        self::assertStringContainsString(
            'https://commons.wikimedia.org/wiki/File:LBL_2008_C%C3%B4te_de_Wanne.jpg',
            $packet,
        );
        self::assertStringNotContainsString('cyclingcommons.example/photo/', $packet);
        self::assertStringContainsString('<xmpRights:Marked>True</xmpRights:Marked>', $packet);
    }

    public function testACommonsFileWithNoNamedUploaderStillCarriesTheRest(): void
    {
        $packet = (new XmpRights('https://cyclingcommons.example'))->forCommonsFile(
            'Anon shot.jpg',
            'Wikimedia Commons',
            null,
            'CC0',
        );

        self::assertStringContainsString('Wikimedia Commons', $packet);
        self::assertStringContainsString('https://creativecommons.org/publicdomain/zero/1.0/', $packet);
        self::assertStringNotContainsString('/wiki/User:', $packet, 'no uploader page invented when Commons named none');
    }

    /**
     * The packet says the bytes are not the original bytes.
     *
     * We re-encode to webp and resize. That is not a creative derivative, but a
     * reader comparing this file to the one on Commons should not have to guess
     * why they differ, and CC BY-SA asks for changes to be indicated.
     */
    public function testACommonsPacketSaysTheCopyWasReencoded(): void
    {
        $packet = (new XmpRights('https://cyclingcommons.example'))->forCommonsFile(
            'Anon shot.jpg',
            'Wikimedia Commons',
            null,
            'CC0',
        );

        self::assertMatchesRegularExpression('~resiz|re-?encod~i', $packet);
    }

    public function testStatesTheLicenceLiterallyAndLinksThePhotoPage(): void
    {
        $packet = self::packet();

        self::assertStringContainsString('CC BY-SA 4.0', $packet);
        self::assertStringContainsString(XmpRights::LICENSE_URL, $packet);
        self::assertStringContainsString(
            'https://cyclingcommons.example/photo/'.self::MEDIA_UUID,
            $packet,
            'the licence must travel with the file, and so must the way back to the attribution',
        );
        self::assertStringContainsString('<xmpRights:Marked>True</xmpRights:Marked>', $packet);
    }

    public function testNeverEmbedsAPhotographerName(): void
    {
        $packet = self::packet();

        self::assertStringNotContainsString('dc:creator', $packet, 'a name in a file cannot be withdrawn');
        self::assertStringNotContainsString('cc:attributionName', $packet, 'display names are neither stable nor verified');
    }

    public function testIsWellFormedXml(): void
    {
        $body = preg_replace('#<\?xpacket[^>]*\?>#', '', self::packet());
        self::assertIsString($body);

        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertNotFalse($document, 'a malformed packet would make every stored file unreadable to metadata tools');
    }

    public function testASiteUrlWithATrailingSlashDoesNotDoubleUp(): void
    {
        self::assertStringContainsString(
            'https://cyclingcommons.example/photo/'.self::MEDIA_UUID,
            self::packet('https://cyclingcommons.example/'),
        );
        self::assertStringNotContainsString('example//photo', self::packet('https://cyclingcommons.example/'));
    }
}
