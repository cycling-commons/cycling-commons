<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\Commons\CommonsApi;
use App\Media\Commons\CommonsPhotoRepository;
use App\Media\Commons\CommonsPhotoState;
use App\Media\MediaStorage;
use App\Media\Message\FetchCommonsPhoto;
use App\Media\MessageHandler\FetchCommonsPhotoHandler;
use App\Media\PhotoProcessor;
use App\Media\Scan\ScanVerdict;
use App\Media\Scan\VirusScannerInterface;
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

    public function testARedeliveryOfASettledRowChangesNothing(): void
    {
        $downloads = 0;
        $this->handle($this->client($this->metadata('CC BY-SA 3.0'), $downloads));
        self::assertSame(1, $downloads);

        $this->handle($this->client($this->metadata('CC BY-SA 3.0'), $downloads));
        self::assertSame(1, $downloads, 'a redelivered message must not re-download');
    }

    private function handle(MockHttpClient $client, ?ScanVerdict $verdict = null): void
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
            new NullLogger(),
        );
        $handler(new FetchCommonsPhoto(self::FILE, 'EU'));
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

    private function metadata(?string $shortName): string
    {
        $extra = ['Artist' => ['value' => '<a href="//commons.wikimedia.org/wiki/User:Jean-Pol_GRANDMONT">Jean-Pol GRANDMONT</a>']];
        if (null !== $shortName) {
            $extra['LicenseShortName'] = ['value' => $shortName];
        }

        return json_encode(['query' => ['pages' => [['imageinfo' => [[
            'thumburl' => 'https://upload.wikimedia.org/thumb/Test_handler.jpg',
            'extmetadata' => $extra,
        ]]]]]], \JSON_THROW_ON_ERROR);
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

    /** @return array{file: string, state: string, credit: ?string, credit_user: ?string, license: ?string, storage_bucket: ?string, storage_prefix: ?string, width: ?int, height: ?int} */
    private function row(): array
    {
        $row = $this->photos->find(self::FILE);
        self::assertNotNull($row);

        return $row;
    }
}
