<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\ContinentResolver;
use App\Media\MediaStorage;
use App\Media\ShardUnavailable;
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
        $this->filesystem = static::getContainer()->get('media.storage.eu01');
    }

    private static function photo(): ProcessedPhoto
    {
        return new ProcessedPhoto('ORIGINAL', 'LARGE', 'SMALL', 1200, 900);
    }

    public function testStoresTheTrioReadsBackAndDeletes(): void
    {
        $this->storage->store('test-bucket-eu-01', 'photos/abc', self::photo());

        self::assertTrue($this->filesystem->fileExists('photos/abc/orig.webp'));
        self::assertSame('LARGE', $this->filesystem->read('photos/abc/lg.webp'));
        self::assertSame('SMALL', $this->filesystem->read('photos/abc/sm.webp'));

        $this->storage->deletePrefix('test-bucket-eu-01', 'photos/abc');

        self::assertFalse($this->filesystem->fileExists('photos/abc/orig.webp'));
        self::assertFalse($this->filesystem->fileExists('photos/abc/sm.webp'));
    }

    public function testDeletingAPrefixThatIsAlreadyGoneIsNotAnError(): void
    {
        $this->storage->deletePrefix('test-bucket-eu-01', 'photos/never-existed');

        self::assertFalse($this->filesystem->fileExists('photos/never-existed/orig.webp'));
    }

    public function testAnOnboardedContinentUsesItsOwnPublicBase(): void
    {
        self::assertSame(
            'https://media.test/img/eu-01/photos/abc/sm.webp',
            $this->storage->url('EU-01', 'photos/abc', 'sm'),
        );
        self::assertSame(
            'https://media.test/img/eu-01/photos/abc/lg.webp',
            $this->storage->url('eu-01', 'photos/abc', 'lg'),
            'the continent code is case-insensitive at the call site',
        );
    }

    public function testTheUrlIsTheBasePlusTheLowercaseShardSegment(): void
    {
        self::assertSame(
            'https://media.test/img/oc-01/photos/abc/orig.webp',
            $this->storage->url('OC-01', 'photos/abc', 'orig'),
            'one shape everywhere: <base>/<lowercase shard>/<key>, the segment the proxy routes on',
        );
    }

    public function testAnUnknownVariantIsARefusalNotAGuess(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->storage->url('EU-01', 'photos/abc', 'huge');
    }

    public function testAnUnknownBucketRefusesInsteadOfGuessing(): void
    {
        // Owner 2026-08-18: "storage must fail". In the suite only the
        // preloaded in-memory buckets exist; a name outside them refuses.
        $this->expectException(ShardUnavailable::class);
        $this->storage->store('bucket-nobody-provisioned', 'photos/south', self::photo());
    }

    public function testActiveForRefusesAContinentWithoutItsEnvPair(): void
    {
        $this->expectException(ShardUnavailable::class);
        $this->storage->activeFor('AQ');
    }

    public function testActiveForAnswersTheShardAndBucketPair(): void
    {
        self::assertSame(['EU-01', 'test-bucket-eu-01'], $this->storage->activeFor('EU'));
        self::assertSame(['EU-01', 'test-bucket-eu-01'], $this->storage->activeFor('eu'), 'case-insensitive at the call site');
    }

    public function testUnresolvableCoordinatesResolveToNoContinentAtAll(): void
    {
        // Owner 2026-08-18: "not part of a continent, we can not accept it".
        // Null, never a default: the caller refuses the upload.
        $resolver = static::getContainer()->get(ContinentResolver::class);

        self::assertNull($resolver->resolve(null, null));
        self::assertNull($resolver->resolve(0.0, 0.0), 'the Atlantic is in no region');
        self::assertNull($resolver->resolve(\NAN, 5.0));
    }
}
