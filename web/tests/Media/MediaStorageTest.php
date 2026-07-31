<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\ContinentResolver;
use App\Media\MediaStorage;
use App\Media\ProcessedPhoto;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The storage shard router (docs/specs/photo-uploads.md §1.2, §2): three
 * objects per photo under photos/<uuid>/, written into the continent's storage,
 * addressed publicly through that continent's own base URL.
 *
 * The test env binds the in-memory adapter, so nothing touches a disk.
 */
final class MediaStorageTest extends KernelTestCase
{
    private MediaStorage $storage;
    private FilesystemOperator $filesystem;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->storage = static::getContainer()->get(MediaStorage::class);
        $this->filesystem = static::getContainer()->get('media.storage.eu');
    }

    private static function photo(): ProcessedPhoto
    {
        return new ProcessedPhoto('ORIGINAL', 'LARGE', 'SMALL', 1200, 900);
    }

    public function testStoresTheTrioReadsBackAndDeletes(): void
    {
        $this->storage->store('EU', 'photos/abc', self::photo());

        self::assertTrue($this->filesystem->fileExists('photos/abc/orig.webp'));
        self::assertSame('LARGE', $this->filesystem->read('photos/abc/lg.webp'));
        self::assertSame('SMALL', $this->filesystem->read('photos/abc/sm.webp'));

        $this->storage->deletePrefix('EU', 'photos/abc');

        self::assertFalse($this->filesystem->fileExists('photos/abc/orig.webp'));
        self::assertFalse($this->filesystem->fileExists('photos/abc/sm.webp'));
    }

    public function testDeletingAPrefixThatIsAlreadyGoneIsNotAnError(): void
    {
        $this->storage->deletePrefix('EU', 'photos/never-existed');

        self::assertFalse($this->filesystem->fileExists('photos/never-existed/orig.webp'));
    }

    public function testAnOnboardedContinentUsesItsOwnPublicBase(): void
    {
        self::assertSame(
            'https://media.test/cc-media-eu/photos/abc/sm.webp',
            $this->storage->url('EU', 'photos/abc', 'sm'),
        );
        self::assertSame(
            'https://media.test/cc-media-eu/photos/abc/lg.webp',
            $this->storage->url('eu', 'photos/abc', 'lg'),
            'the continent code is case-insensitive at the call site',
        );
    }

    public function testAContinentWithoutItsOwnBaseFallsBackToAPathSegment(): void
    {
        self::assertSame(
            'https://media.test/oc/photos/abc/orig.webp',
            $this->storage->url('OC', 'photos/abc', 'orig'),
            'the fallback is the production shape: the continent as a proxy-routed path segment',
        );
    }

    public function testAnUnknownVariantIsARefusalNotAGuess(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->storage->url('EU', 'photos/abc', 'huge');
    }

    public function testAnUnonboardedContinentFallsBackToTheDefaultStorage(): void
    {
        $this->storage->store('AQ', 'photos/south', self::photo());

        self::assertTrue(
            $this->filesystem->fileExists('photos/south/orig.webp'),
            'a continent with no bucket yet writes into the default shard rather than failing the upload',
        );
    }

    public function testUnresolvableCoordinatesFallBackToTheDefaultContinent(): void
    {
        $resolver = static::getContainer()->get(ContinentResolver::class);

        self::assertSame('EU', $resolver->resolve(null, null));
        self::assertSame('EU', $resolver->resolve(0.0, 0.0), 'the Atlantic is in no region');
        self::assertSame('EU', $resolver->resolve(\NAN, 5.0));
    }
}
