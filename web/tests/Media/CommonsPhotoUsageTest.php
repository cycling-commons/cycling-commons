<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\Commons\CommonsPhotoUsage;
use PHPUnit\Framework\TestCase;

/**
 * Reading which Commons file a stored photo entry names, in the form
 * `commons_photo.file` holds, so a file is never deleted while an entry still
 * names it under a different spelling.
 */
final class CommonsPhotoUsageTest extends TestCase
{
    public function testAnEntryNamesItsFileByItsSourcePage(): void
    {
        self::assertSame('Col du Galibier (2).jpg', CommonsPhotoUsage::fileOf([
            'sm' => 'https://media.test/img/x/sm.webp',
            'source' => 'https://commons.wikimedia.org/wiki/File:'.rawurlencode('Col_du_Galibier_(2).jpg'),
        ]));
    }

    public function testAnEntryWithoutASourceNamesTheFileItHotlinks(): void
    {
        self::assertSame('Lac bleu.jpg', CommonsPhotoUsage::fileOf([
            'sm' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Lac_bleu.jpg?width=640',
        ]));
        self::assertSame('Lac bleu.jpg', CommonsPhotoUsage::fileOf('https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Lac_bleu.jpg/640px-Lac_bleu.jpg'));
    }

    public function testARiderPhotoOrAForeignImageNamesNoFile(): void
    {
        self::assertNull(CommonsPhotoUsage::fileOf(['id' => 'bbbbbbbb-0000-4000-8000-000000000001', 'sm' => 'https://media.test/img/x/sm.webp']));
        self::assertNull(CommonsPhotoUsage::fileOf('https://example.test/view.jpg'));
        self::assertNull(CommonsPhotoUsage::fileOf(42));
    }

    public function testFilesInReadsPhotoAndEveryGalleryEntryOnce(): void
    {
        $page = static fn (string $f): array => ['source' => 'https://commons.wikimedia.org/wiki/File:'.rawurlencode(str_replace(' ', '_', $f))];

        self::assertSame(['A view.jpg', 'B view.jpg'], CommonsPhotoUsage::filesIn([
            'photo' => $page('A view.jpg'),
            'photos' => [$page('B view.jpg'), $page('A view.jpg'), ['id' => 'rider']],
            'wikidata' => 'Q1',
        ]));
        self::assertSame([], CommonsPhotoUsage::filesIn(['photos' => 'not a list']));
    }
}
