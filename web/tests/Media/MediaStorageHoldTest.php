<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\MediaStorage;
use App\Media\ProcessedPhoto;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\TestCase;

/**
 * Moving a held photo between buckets (docs/specs/photo-uploads.md §6d).
 * Evidence first: the source objects go only once the copy is whole.
 */
final class MediaStorageHoldTest extends TestCase
{
    private const string PUBLIC = 'hold-bucket-eu-01';
    private const string PRIVATE = 'hold-private';
    private const string PREFIX = 'published/0000-uuid/r1';

    private function storage(FilesystemOperator $public, FilesystemOperator $private): MediaStorage
    {
        return new MediaStorage(null, [], self::PRIVATE, 'https://img.test', [self::PUBLIC => $public, self::PRIVATE => $private]);
    }

    public function testAWholeMoveEmptiesThePublicPrefix(): void
    {
        $public = new Filesystem(new InMemoryFilesystemAdapter());
        $private = new Filesystem(new InMemoryFilesystemAdapter());
        $storage = $this->storage($public, $private);
        $storage->store(self::PUBLIC, self::PREFIX, new ProcessedPhoto('O', 'L', 'S', 1, 1));

        self::assertTrue($storage->withhold(self::PUBLIC, self::PREFIX));
        self::assertSame(0, $storage->variantsExist(self::PUBLIC, self::PREFIX));
        self::assertSame('L', $private->read('held/'.self::PREFIX.'/lg.webp'));

        self::assertTrue($storage->unwithhold(self::PUBLIC, self::PREFIX));
        self::assertSame(3, $storage->variantsExist(self::PUBLIC, self::PREFIX));
        self::assertSame(0, $storage->heldVariantsExist(self::PREFIX));
    }

    public function testAFailedCopyLeavesThePublicObjectsWhereTheyWere(): void
    {
        $public = new Filesystem(new InMemoryFilesystemAdapter());
        $private = $this->createStub(FilesystemOperator::class);
        $private->method('writeStream')->willThrowException(UnableToWriteFile::atLocation('held/x', 'disk full'));
        $storage = $this->storage($public, $private);
        $storage->store(self::PUBLIC, self::PREFIX, new ProcessedPhoto('O', 'L', 'S', 1, 1));

        self::assertFalse($storage->withhold(self::PUBLIC, self::PREFIX));
        self::assertSame(3, $storage->variantsExist(self::PUBLIC, self::PREFIX), 'nothing deleted before the copy was whole');
    }
}
