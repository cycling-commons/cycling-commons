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
