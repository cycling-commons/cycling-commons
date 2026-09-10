<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\Commons\CommonsFile;
use PHPUnit\Framework\TestCase;

/**
 * The resolver's real job is refusing things.
 *
 * Measured over 487,704 letter-P rows on 2026-08-24: 1,274 carry `image`, and
 * only 639 of those point at Commons. 1,850 carry `wikimedia_commons`, and
 * 1,107 of those name a Category rather than a file. Every refusal below is a
 * shape that exists in the harvest today, not a hypothetical.
 */
final class CommonsFileTest extends TestCase
{
    public function testWikimediaCommonsFileTagWins(): void
    {
        self::assertSame('Roche aux Faucons 2015.jpg', CommonsFile::fromTags([
            'wikimedia_commons' => 'File:Roche aux Faucons 2015.jpg',
            'image' => 'https://example.org/other.jpg',
        ]));
    }

    public function testCategoryIsNotAFile(): void
    {
        self::assertNull(CommonsFile::fromTags(['wikimedia_commons' => 'Category:Ardennes']));
    }

    public function testImageTagShapes(): void
    {
        self::assertSame('Mur de Huy.jpg', CommonsFile::fromTags(['image' => 'File:Mur de Huy.jpg']));
        self::assertSame('Mur de Huy.jpg', CommonsFile::fromTags(
            ['image' => 'https://commons.wikimedia.org/wiki/File:Mur_de_Huy.jpg']));
        self::assertSame('Côte de Wanne.jpg', CommonsFile::fromTags(
            ['image' => 'https://upload.wikimedia.org/wikipedia/commons/a/ab/C%C3%B4te_de_Wanne.jpg']));
    }

    public function testThumbUrlResolvesToTheFileItIsAThumbnailOf(): void
    {
        self::assertSame('Mur de Huy.jpg', CommonsFile::fromTags(
            ['image' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Mur_de_Huy.jpg/800px-Mur_de_Huy.jpg']));
    }

    /**
     * The shape 419 catalog rows actually carry.
     *
     * SeedWikidataPlacesCommand and the Wallonia enrich step wrote
     * `Special:FilePath` URLs straight into `item.attributes->photo`, so
     * localising those rows means reading the filename back out of one. The
     * seeder un-escapes !'()* after rawurlencode, so the decode has to survive
     * both spellings of the same name.
     */
    public function testSpecialFilePathUrlNamesItsFile(): void
    {
        self::assertSame('LBL 2008 Côte de Wanne.jpg', CommonsFile::fromTags([
            'image' => 'https://commons.wikimedia.org/wiki/Special:FilePath/LBL_2008_C%C3%B4te_de_Wanne.jpg?width=520',
        ]));
        self::assertSame("Foto's-0009 Rosiertest.jpg", CommonsFile::fromTags([
            'image' => "https://commons.wikimedia.org/wiki/Special:FilePath/Foto's-0009_Rosiertest.jpg?width=1400",
        ]));
    }

    /**
     * Wikimedia moved thumbnails to their own host, and the redirect chain from
     * Special:FilePath now ends there. Recognising it is what keeps a file we
     * already hold from being re-fetched under a second name.
     */
    public function testThumbHostResolvesToTheFileItIsAThumbnailOf(): void
    {
        self::assertSame('Sint joriskerk te Amersfoort.JPG', CommonsFile::fromTags([
            'image' => 'https://thumb.wikimedia.org/wikipedia/commons/thumb/1/11/Sint_joriskerk_te_Amersfoort.JPG/1920px-Sint_joriskerk_te_Amersfoort.JPG?utm_source=commons.wikimedia.org',
        ]));
    }

    public function testALookalikeFilePathHostIsRefused(): void
    {
        self::assertNull(CommonsFile::fromTags([
            'image' => 'https://commons.wikimedia.org.evil.test/wiki/Special:FilePath/Nice.jpg',
        ]));
        self::assertNull(CommonsFile::fromTags([
            'image' => 'https://thumb.wikimedia.org.evil.test/wikipedia/commons/thumb/1/11/Nice.jpg/800px-Nice.jpg',
        ]));
    }

    public function testArbitraryHostIsRefused(): void
    {
        self::assertNull(CommonsFile::fromTags(['image' => 'https://www.hotel-ardennes.be/photo.jpg']));
        self::assertNull(CommonsFile::fromTags(['image' => 'http://commons.wikimedia.org.evil.test/wiki/File:X.jpg']));
    }

    public function testNonImageExtensionIsRefused(): void
    {
        self::assertNull(CommonsFile::fromTags(['wikimedia_commons' => 'File:Map of the area.pdf']));
        self::assertNull(CommonsFile::fromTags(['wikimedia_commons' => 'File:Tour.ogv']));
    }

    public function testTheSecondTagIsTriedWhenTheFirstIsUnusable(): void
    {
        self::assertSame('Fallback.jpg', CommonsFile::fromTags([
            'wikimedia_commons' => 'Category:Ardennes',
            'image' => 'File:Fallback.jpg',
        ]));
    }

    public function testNothingToResolve(): void
    {
        self::assertNull(CommonsFile::fromTags([]));
        self::assertNull(CommonsFile::fromTags(['image' => '']));
        self::assertNull(CommonsFile::fromTags(['ele' => '484']));
        self::assertNull(CommonsFile::fromTags(['image' => 42]));
    }

    public function testTraversalAndControlCharactersAreRefused(): void
    {
        self::assertNull(CommonsFile::fromTags(['wikimedia_commons' => 'File:../../etc/passwd.jpg']));
        self::assertNull(CommonsFile::fromTags(['wikimedia_commons' => "File:a\nb.jpg"]));
    }
}
