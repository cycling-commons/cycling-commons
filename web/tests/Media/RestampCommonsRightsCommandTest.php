<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\Command\RestampCommonsRightsCommand;
use App\Media\Commons\CommonsApi;
use App\Media\Commons\CommonsPhotoRepository;
use App\Media\Commons\CommonsPhotoState;
use App\Media\MediaStorage;
use App\Media\MessageHandler\FetchCommonsPhotoHandler;
use App\Media\PhotoProcessor;
use App\Media\Scan\ScanVerdict;
use App\Media\Scan\VirusScannerInterface;
use App\Media\XmpRights;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Re-fetching Commons photos that were stored before the rights packet existed.
 *
 * The assertion that matters is the URL. A photo's storage prefix is baked into
 * every item that shows it, so a re-fetch that moved the file would trade one
 * broken thing for another, quietly, and only on the pages nobody opened that
 * day.
 */
final class RestampCommonsRightsCommandTest extends KernelTestCase
{
    private const string FILE = 'Restamp test subject.jpg';

    private Connection $db;
    private CommonsPhotoRepository $photos;
    private MediaStorage $storage;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var Connection $db */
        $db = $container->get('doctrine.dbal.default_connection');
        $this->db = $db;
        /** @var CommonsPhotoRepository $photos */
        $photos = $container->get(CommonsPhotoRepository::class);
        $this->photos = $photos;
        /** @var MediaStorage $storage */
        $storage = $container->get(MediaStorage::class);
        $this->storage = $storage;

        $this->db->executeStatement('DELETE FROM commons_photo WHERE file = :f', ['f' => self::FILE]);
    }

    public function testAnUnstampedPhotoIsRefetchedAtTheSameUrl(): void
    {
        $this->storeWithoutPacket();
        $before = $this->photos->find(self::FILE);

        $tester = $this->restamp();
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        $after = $this->photos->find(self::FILE);
        self::assertSame(CommonsPhotoState::Ready->value, $after['state']);
        self::assertSame($before['storage_bucket'], $after['storage_bucket'], 'the bucket must not move');
        self::assertSame($before['storage_prefix'], $after['storage_prefix'], 'the prefix is in every published URL and must not move');

        $xmp = $this->storedXmp((string) $after['storage_bucket'], (string) $after['storage_prefix']);
        self::assertStringContainsString('CC BY-SA 3.0', $xmp);
        self::assertStringContainsString('Jean-Pol GRANDMONT', $xmp);
        self::assertStringContainsString('commons.wikimedia.org/wiki/File:Restamp_test_subject.jpg', $xmp);
    }

    public function testAPhotoThatAlreadyCarriesAPacketIsNotRefetched(): void
    {
        $this->storeWithoutPacket();
        $downloads = 0;
        $this->restamp($downloads);
        self::assertSame(1, $downloads);

        // Second pass: the file now carries a packet, so nothing should move.
        $this->restamp($downloads);
        self::assertSame(1, $downloads, 'a stamped photo must not be fetched again');
    }

    public function testDryRunChangesNothing(): void
    {
        $this->storeWithoutPacket();
        $downloads = 0;

        $tester = $this->restamp($downloads, ['--dry-run' => true]);

        self::assertSame(0, $downloads, 'a dry run must not download');
        self::assertStringContainsString('would be re-fetched', $tester->getDisplay());
        self::assertSame(CommonsPhotoState::Ready->value, $this->photos->find(self::FILE)['state']);
    }

    /**
     * The row is put back to `ready` by the handler, never left pending.
     *
     * A row stuck at pending is the failure mode the drawer shows as a spinner
     * that never resolves, so a re-stamp that died halfway would be worse than
     * one that never ran.
     */
    public function testTheRowDoesNotStayPending(): void
    {
        $this->storeWithoutPacket();
        $this->restamp();

        self::assertSame(CommonsPhotoState::Ready->value, $this->photos->find(self::FILE)['state']);
    }

    /** A ready row whose stored file carries no XMP: the state before the fix. */
    private function storeWithoutPacket(): void
    {
        $this->photos->claim(self::FILE);
        $container = self::getContainer();
        /** @var PhotoProcessor $processor */
        $processor = $container->get(PhotoProcessor::class);

        $photo = $processor->process(self::jpeg());          // no packet, exactly as before
        $bucket = $this->storage->bucketFor('EU');
        $prefix = 'published/restamp-test/'.bin2hex(random_bytes(4));
        $this->storage->store($bucket, $prefix, $photo);
        $this->photos->markReady(
            self::FILE, $bucket, $prefix,
            'Jean-Pol GRANDMONT', 'Jean-Pol_GRANDMONT', 'CC BY-SA 3.0',
            $photo->width, $photo->height,
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function restamp(int &$downloads = 0, array $options = []): CommandTester
    {
        $container = self::getContainer();
        /** @var PhotoProcessor $processor */
        $processor = $container->get(PhotoProcessor::class);

        $handler = new FetchCommonsPhotoHandler(
            new CommonsApi($this->client($downloads), 'CyclingCommons-test/1.0'),
            $this->photos,
            $processor,
            $this->storage,
            $this->scanner(),
            new XmpRights('https://cyclingcommons.example'),
            new NullLogger(),
        );

        $command = new RestampCommonsRightsCommand($this->db, $this->photos, $this->storage, $handler);
        $application = new Application(self::$kernel);
        $application->addCommand($command);

        $tester = new CommandTester($application->find('app:media:restamp-commons-rights'));
        // --sleep=0: the polite pause is for Wikimedia, not for the suite.
        $tester->execute($options + ['--sleep' => '0']);

        return $tester;
    }

    private function storedXmp(string $bucket, string $prefix): string
    {
        $stream = $this->storage->readStream($bucket, $prefix, 'lg');
        self::assertIsResource($stream);
        $bytes = stream_get_contents($stream);
        self::assertIsString($bytes);

        $image = new \Imagick();
        $image->readImageBlob($bytes);
        $xmp = $image->getImageProfile('xmp');
        $image->clear();

        return $xmp;
    }

    private function scanner(): VirusScannerInterface
    {
        return new class implements VirusScannerInterface {
            #[\Override]
            public function scan(mixed $bytes): ScanVerdict
            {
                return ScanVerdict::clean();
            }
        };
    }

    private function client(int &$downloads): MockHttpClient
    {
        $metadata = json_encode(['query' => ['pages' => [['imageinfo' => [[
            'thumburl' => 'https://thumb.wikimedia.org/thumb/Restamp_test_subject.jpg',
            'extmetadata' => [
                'LicenseShortName' => ['value' => 'CC BY-SA 3.0'],
                'Artist' => ['value' => '<a href="//commons.wikimedia.org/wiki/User:Jean-Pol_GRANDMONT">Jean-Pol GRANDMONT</a>'],
            ],
        ]]]]]], \JSON_THROW_ON_ERROR);

        return new MockHttpClient(static function (string $method, string $url) use ($metadata, &$downloads): MockResponse {
            if (str_contains($url, 'api.php')) {
                return new MockResponse($metadata, ['http_code' => 200]);
            }
            ++$downloads;

            return new MockResponse(self::jpeg(), ['http_code' => 200]);
        });
    }

    private static function jpeg(): string
    {
        $image = new \Imagick();
        $image->newImage(1400, 933, 'green');
        $image->setImageFormat('jpeg');
        $bytes = $image->getImageBlob();
        $image->clear();

        return $bytes;
    }
}
