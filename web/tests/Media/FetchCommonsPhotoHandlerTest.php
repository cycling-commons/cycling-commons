<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\Commons\CommonsApi;
use App\Media\Commons\CommonsPhotoAdmission;
use App\Media\Commons\CommonsPhotoRepository;
use App\Media\Commons\CommonsPhotoState;
use App\Media\MediaStorage;
use App\Media\Message\FetchCommonsPhoto;
use App\Media\MessageHandler\FetchCommonsPhotoHandler;
use App\Media\PhotoPlace;
use App\Media\PhotoProcessor;
use App\Media\Scan\ScanVerdict;
use App\Media\Scan\VirusScannerInterface;
use App\Media\XmpRights;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The order of the steps is the design, so these tests pin the order and not
 * only the outcome: a file we may not republish must cost one metadata call and
 * no download at all, and nothing may reach a bucket without a clean scan.
 */
final class FetchCommonsPhotoHandlerTest extends KernelTestCase
{
    private const string FILE = 'Test handler.jpg';

    private CommonsPhotoRepository $photos;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var CommonsPhotoRepository $photos */
        $photos = self::getContainer()->get(CommonsPhotoRepository::class);
        $this->photos = $photos;
        /** @var Connection $db */
        $db = self::getContainer()->get('doctrine.dbal.default_connection');
        $db->executeStatement('DELETE FROM commons_photo WHERE file = :f', ['f' => self::FILE]);
        $this->photos->claim(self::FILE);
    }

    public function testAnUnfreeLicenceIsNeverDownloaded(): void
    {
        $downloads = 0;
        $this->handle($this->client($this->metadata('Fair use'), $downloads));

        self::assertSame(0, $downloads, 'the licence gate must run before any bytes are pulled');
        self::assertSame(CommonsPhotoState::Unusable->value, $this->row()['state']);
        self::assertSame('licence', $this->row()['failed_reason']);
    }

    public function testAFileNamingNoAuthorIsNeverDownloaded(): void
    {
        $downloads = 0;
        $this->handle($this->client($this->metadata('CC BY-SA 3.0', artist: ''), $downloads));

        self::assertSame(0, $downloads);
        self::assertSame(CommonsPhotoState::Unusable->value, $this->row()['state']);
        self::assertSame('no_author', $this->row()['failed_reason'], 'never credited to "Wikimedia Commons"');
    }

    public function testNoMachineReadableAuthorFallsBackToTheUploader(): void
    {
        $downloads = 0;
        $this->handle($this->client($this->metadata('CC BY-SA 3.0', artist: '<a href="//commons.wikimedia.org/wiki/User:Jane_Rider">No machine-readable author provided. Jane_Rider assumed (based on copyright claims).</a>'), $downloads));

        self::assertSame(1, $downloads);
        self::assertSame(CommonsPhotoState::Ready->value, $this->row()['state']);
        self::assertSame('Jane Rider', $this->row()['credit']);
    }

    public function testCommonsNonFreeAndRestrictionFlagsAreRefusals(): void
    {
        $downloads = 0;
        $this->handle($this->client($this->metadata('CC BY-SA 3.0', extra: ['NonFree' => ['value' => 'true']]), $downloads));
        self::assertSame(0, $downloads);
        self::assertSame('non_free', $this->row()['failed_reason']);

        $this->reclaim();
        $this->handle($this->client($this->metadata('CC BY-SA 3.0', extra: ['Restrictions' => ['value' => 'trademarked']]), $downloads));
        self::assertSame(0, $downloads);
        self::assertSame('restricted', $this->row()['failed_reason']);
    }

    /** A missing file is not a licence refusal, so a licence recheck does not re-queue it. */
    public function testAMissingFileIsNotALicenceRefusal(): void
    {
        $downloads = 0;
        $this->handle($this->client((string) json_encode(['query' => ['pages' => [['missing' => true]]]]), $downloads));

        self::assertSame(CommonsPhotoState::Unusable->value, $this->row()['state']);
        self::assertSame('no_file', $this->row()['failed_reason']);
    }

    /**
     * A scenic view asking for a file whose camera stood far away costs one
     * metadata call and no download. The row keeps what Commons said, so the
     * refusal is known without asking again, and another place may still use
     * the file (CommonsPhotoAdmission::admit()).
     */
    public function testAScenicViewDoesNotDownloadAFileTakenElsewhere(): void
    {
        $downloads = 0;
        $far = [['lat' => 50.43234, 'lon' => 5.81234, 'primary' => true, 'type' => 'camera']];
        $this->handle($this->client($this->metadata('CC BY-SA 3.0', $far), $downloads), place: new PhotoPlace('P', 50.41234, 5.81234));

        $row = $this->row();
        self::assertSame(0, $downloads, 'nothing is downloaded for a place that may not show it');
        self::assertSame(CommonsPhotoState::Declined->value, $row['state']);
        self::assertSame('camera_far', $row['failed_reason']);
        self::assertSame('CC BY-SA 3.0', $row['license']);
        self::assertSame('Jean-Pol GRANDMONT', $row['credit']);
        self::assertSame(50.43234, $row['camera_lat']);
        self::assertNull($row['storage_prefix']);
    }

    public function testAScenicViewDoesNotDownloadAFileWithNoCamera(): void
    {
        $downloads = 0;
        $this->handle($this->client($this->metadata('CC BY-SA 3.0'), $downloads), place: new PhotoPlace('P', 50.41234, 5.81234));

        self::assertSame(0, $downloads);
        self::assertSame(CommonsPhotoState::Declined->value, $this->row()['state']);
        self::assertSame('camera_unknown', $this->row()['failed_reason']);
    }

    public function testAScenicViewDownloadsAFileTakenAtItsPin(): void
    {
        $downloads = 0;
        $near = [['lat' => 50.41244, 'lon' => 5.81234, 'primary' => true, 'type' => 'camera']];
        $this->handle($this->client($this->metadata('CC BY-SA 3.0', $near), $downloads), place: new PhotoPlace('P', 50.41234, 5.81234));

        self::assertSame(1, $downloads);
        self::assertSame(CommonsPhotoState::Ready->value, $this->row()['state']);
    }

    public function testAFileWithNoLicenceAtAllIsRefused(): void
    {
        $downloads = 0;
        $this->handle($this->client($this->metadata(null), $downloads));

        self::assertSame(0, $downloads);
        self::assertSame(CommonsPhotoState::Unusable->value, $this->row()['state']);
    }

    public function testACleanFreeFileBecomesReady(): void
    {
        $downloads = 0;
        $this->handle($this->client($this->metadata('CC BY-SA 3.0'), $downloads));

        $row = $this->row();
        self::assertSame(1, $downloads);
        self::assertSame(CommonsPhotoState::Ready->value, $row['state']);
        self::assertSame('Jean-Pol GRANDMONT', $row['credit']);
        self::assertSame('Jean-Pol_GRANDMONT', $row['credit_user'], 'the User: link is parsed out of the Artist HTML');
        self::assertSame('CC BY-SA 3.0', $row['license']);
        self::assertSame('test-bucket-eu-01', $row['storage_bucket']);
        self::assertNotNull($row['storage_prefix']);
    }

    public function testCommonsBeingDownIsFailedNotUnusable(): void
    {
        $downloads = 0;
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 503]));
        $this->handle($client);

        self::assertSame(CommonsPhotoState::Failed->value, $this->row()['state'],
            'our outage stays retryable; only a verdict about the file is terminal');
    }

    public function testAnInfectedFileNeverReachesABucket(): void
    {
        $downloads = 0;
        $this->handle($this->client($this->metadata('CC BY-SA 3.0'), $downloads), ScanVerdict::infected('Eicar-Test-Signature'));

        $row = $this->row();
        self::assertSame(CommonsPhotoState::Unusable->value, $row['state']);
        self::assertNull($row['storage_bucket'], 'nothing was stored');
    }

    /**
     * The stored bytes must carry the file's own rights, not ours and not none.
     *
     * The re-encode drops whatever XMP arrived from Commons, so for a while
     * every Commons photo in our bucket was an orphan: no author, no licence,
     * nothing. A caption on one HTML page is not the attribution travelling
     * with the work, which is what CC BY-SA asks for, and the file is the thing
     * that gets downloaded.
     *
     * Reading the object back rather than trusting the wiring: the packet has
     * to survive the webp encode, and that is the step that used to eat it.
     */
    public function testTheStoredFileCarriesTheCommonsRightsPacket(): void
    {
        $downloads = 0;
        $this->handle($this->client($this->metadata('CC BY-SA 3.0'), $downloads));

        $row = $this->row();
        self::assertSame(CommonsPhotoState::Ready->value, $row['state']);

        /** @var MediaStorage $storage */
        $storage = self::getContainer()->get(MediaStorage::class);
        // `lg`, not `sm`: the thumbnail deliberately carries no packet (a 1 KB
        // rights block on a 520px preview is most of the file), exactly as for
        // a rider's photo. `orig` and `lg` are the ones anybody downloads.
        $stream = $storage->readStream((string) $row['storage_bucket'], (string) $row['storage_prefix'], 'lg');
        self::assertIsResource($stream);
        $bytes = stream_get_contents($stream);
        self::assertIsString($bytes);

        $image = new \Imagick();
        $image->readImageBlob($bytes);
        $xmp = $image->getImageProfile('xmp');
        $image->clear();

        self::assertStringContainsString('CC BY-SA 3.0', $xmp, 'the licence must be in the file, not only in the caption');
        self::assertStringContainsString(
            'https://creativecommons.org/licenses/by-sa/3.0/',
            $xmp,
            'their deed, resolved through LicenceUrls',
        );
        self::assertStringContainsString('Jean-Pol GRANDMONT', $xmp, 'the author Commons named');
        self::assertStringContainsString(
            'https://commons.wikimedia.org/wiki/File:Test_handler.jpg',
            $xmp,
            'attribution points back at the Commons file page',
        );
        self::assertStringNotContainsString(
            XmpRights::LICENSE_URL,
            $xmp,
            'our own default licence must never be asserted over somebody else\'s work',
        );
    }

    /**
     * Where the camera stood is kept with the photo and published with it, so
     * a scenic view can tell a photo of its own view from one taken elsewhere
     * (PhotoValidator).
     */
    public function testTheCameraPointIsStoredAndPublished(): void
    {
        $downloads = 0;
        $this->handle($this->client($this->metadata('CC BY-SA 3.0', [['lat' => 50.41234, 'lon' => 5.81234, 'primary' => true, 'type' => 'camera']]), $downloads));

        $row = $this->row();
        self::assertSame(50.41234, $row['camera_lat']);
        self::assertSame(5.81234, $row['camera_lng']);
        self::assertNotNull($this->cameraCheckedAt(), 'the fetch asked, so the backfill need not');

        /** @var CommonsPhotoAdmission $admission */
        $admission = self::getContainer()->get(CommonsPhotoAdmission::class);
        self::assertSame([50.41234, 5.81234], $admission->readyPhoto(self::FILE)['cameraAt'] ?? null);
    }

    public function testAFileWithNoCameraIsStillCheckedAndPublishesNone(): void
    {
        $downloads = 0;
        $this->handle($this->client($this->metadata('CC BY-SA 3.0'), $downloads));

        self::assertNull($this->row()['camera_lat']);
        self::assertNotNull($this->cameraCheckedAt());

        /** @var CommonsPhotoAdmission $admission */
        $admission = self::getContainer()->get(CommonsPhotoAdmission::class);
        $ready = $admission->readyPhoto(self::FILE);
        self::assertNotNull($ready);
        self::assertArrayNotHasKey('cameraAt', $ready);
    }

    public function testARedeliveryOfASettledRowChangesNothing(): void
    {
        $downloads = 0;
        $this->handle($this->client($this->metadata('CC BY-SA 3.0'), $downloads));
        self::assertSame(1, $downloads);

        $this->handle($this->client($this->metadata('CC BY-SA 3.0'), $downloads));
        self::assertSame(1, $downloads, 'a redelivered message must not re-download');
    }

    private function reclaim(): void
    {
        /** @var Connection $db */
        $db = self::getContainer()->get('doctrine.dbal.default_connection');
        $db->executeStatement('DELETE FROM commons_photo WHERE file = :f', ['f' => self::FILE]);
        $this->photos->claim(self::FILE);
    }

    private function handle(MockHttpClient $client, ?ScanVerdict $verdict = null, ?PhotoPlace $place = null): void
    {
        $container = self::getContainer();
        /** @var PhotoProcessor $processor */
        $processor = $container->get(PhotoProcessor::class);
        /** @var MediaStorage $storage */
        $storage = $container->get(MediaStorage::class);

        $handler = new FetchCommonsPhotoHandler(
            new CommonsApi($client, 'CyclingCommons-test/1.0'),
            $this->photos,
            $processor,
            $storage,
            $this->scanner($verdict ?? ScanVerdict::clean()),
            new XmpRights('https://cyclingcommons.example'),
            new NullLogger(),
        );
        $handler(FetchCommonsPhoto::forPlace(self::FILE, 'EU', $place ?? PhotoPlace::unplaced()));
    }

    private function scanner(ScanVerdict $verdict): VirusScannerInterface
    {
        return new class($verdict) implements VirusScannerInterface {
            public function __construct(private readonly ScanVerdict $verdict)
            {
            }

            #[\Override]
            public function scan(mixed $bytes): ScanVerdict
            {
                return $this->verdict;
            }
        };
    }

    /**
     * @param list<array<string, mixed>>|null $coordinates
     * @param array<string, mixed>            $extra
     */
    private function metadata(?string $shortName, ?array $coordinates = null, string $artist = '<a href="//commons.wikimedia.org/wiki/User:Jean-Pol_GRANDMONT">Jean-Pol GRANDMONT</a>', array $extra = []): string
    {
        $extra['Artist'] = ['value' => $artist];
        if (null !== $shortName) {
            $extra['LicenseShortName'] = ['value' => $shortName];
        }

        $page = ['imageinfo' => [[
            'thumburl' => 'https://upload.wikimedia.org/thumb/Test_handler.jpg',
            'extmetadata' => $extra,
        ]]];
        if (null !== $coordinates) {
            $page['coordinates'] = $coordinates;
        }

        return json_encode(['query' => ['pages' => [$page]]], \JSON_THROW_ON_ERROR);
    }

    private function cameraCheckedAt(): ?string
    {
        /** @var Connection $db */
        $db = self::getContainer()->get('doctrine.dbal.default_connection');
        $at = $db->fetchOne('SELECT camera_checked_at FROM commons_photo WHERE file = :f', ['f' => self::FILE]);

        return \is_string($at) ? $at : null;
    }

    private function client(string $metadataJson, int &$downloads): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url) use ($metadataJson, &$downloads): MockResponse {
            if (str_contains($url, 'api.php')) {
                return new MockResponse($metadataJson, ['http_code' => 200]);
            }
            ++$downloads;

            return new MockResponse(self::jpeg(), ['http_code' => 200]);
        });
    }

    private static function jpeg(): string
    {
        $image = new \Imagick();
        $image->newImage(1400, 933, 'red');
        $image->setImageFormat('jpeg');
        $bytes = $image->getImageBlob();
        $image->clear();

        return $bytes;
    }

    /** @return array{file: string, state: string, failed_reason: ?string, credit: ?string, credit_user: ?string, license: ?string, storage_bucket: ?string, storage_prefix: ?string, width: ?int, height: ?int, camera_lat: ?float, camera_lng: ?float} */
    private function row(): array
    {
        $row = $this->photos->find(self::FILE);
        self::assertNotNull($row);

        return $row;
    }
}
