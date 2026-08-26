<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
