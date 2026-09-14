<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\Commons\CommonsApi;
use App\Media\Commons\CommonsPhotoAdmission;
use App\Media\Commons\CommonsPhotoRepository;
use App\Media\Commons\WikidataImageRepository;
use App\Media\MediaStorage;
use App\Media\Message\FetchCommonsPhoto;
use App\Media\Message\ResolveWikidataImage;
use App\Media\MessageHandler\ResolveWikidataImageHandler;
use App\Media\PhotoPlace;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Two thirds of Wikidata items have no P18 (196 of 600 sampled, 2026-08-24), so
 * "asked, and there is none" is the answer this cache exists to remember. Not
 * remembering it would re-ask Wikidata on every single drawer open.
 */
final class WikidataImageTest extends KernelTestCase
{
    private WikidataImageRepository $images;
    private CommonsPhotoRepository $photos;
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        /** @var WikidataImageRepository $images */
        $images = $c->get(WikidataImageRepository::class);
        $this->images = $images;
        /** @var CommonsPhotoRepository $photos */
        $photos = $c->get(CommonsPhotoRepository::class);
        $this->photos = $photos;
        /** @var Connection $db */
        $db = $c->get('doctrine.dbal.default_connection');
        $this->db = $db;
        $db->executeStatement("DELETE FROM wikidata_image WHERE qid LIKE 'Q9999999%'");
        $db->executeStatement("DELETE FROM commons_photo WHERE file LIKE 'Test wd%'");
    }

    public function testAnAbsentP18IsARememberedAnswerNotAGap(): void
    {
        $this->handle('Q99999901', $this->claims(null));

        $row = $this->images->find('Q99999901');
        self::assertNotNull($row);
        self::assertTrue($row['answered'], 'we asked');
        self::assertNull($row['file'], 'and there is no image');
        self::assertFalse($this->images->claim('Q99999901'), 'so Wikidata is never asked twice');
    }

    public function testAHitQueuesTheCommonsFetch(): void
    {
        $sent = [];
        $this->handle('Q99999902', $this->claims('Test wd hit.jpg'), $sent);

        $row = $this->images->find('Q99999902');
        self::assertNotNull($row);
        self::assertSame('Test wd hit.jpg', $row['file']);
        self::assertCount(1, $sent);
        self::assertInstanceOf(FetchCommonsPhoto::class, $sent[0]);
        self::assertSame('Test wd hit.jpg', $sent[0]->file);
    }

    public function testTheFetchCarriesThePlaceAndADeclinedFileStaysDeclinedForIt(): void
    {
        $sent = [];
        $this->handle('Q99999906', $this->claims('Test wd view.jpg'), $sent, new PhotoPlace('P', 50.4, 5.8));
        self::assertCount(1, $sent);
        self::assertInstanceOf(FetchCommonsPhoto::class, $sent[0]);
        self::assertSame('P', $sent[0]->letter);
        self::assertSame(50.4, $sent[0]->lat);

        // The fetch declined it: its camera stood 400 m from this view.
        $this->photos->markDeclined('Test wd view.jpg', 'camera_far', 'Jane', null, 'CC BY-SA 4.0', 50.4036, 5.8);
        $again = [];
        $this->handle('Q99999907', $this->claims('Test wd view.jpg'), $again, new PhotoPlace('P', 50.4, 5.8));
        self::assertCount(0, $again, 'a second scenic item naming the same file far from its camera queues nothing');

        $this->handle('Q99999908', $this->claims('Test wd view.jpg'), $again, new PhotoPlace('Q', 50.4, 5.8));
        self::assertCount(1, $again, 'a castle may show it, so it is fetched');
    }

    public function testUnderscoresBecomeSpacesToMatchCommonsFileNames(): void
    {
        $this->handle('Q99999903', $this->claims('Test wd_under score.jpg'));

        $row = $this->images->find('Q99999903');
        self::assertNotNull($row);
        self::assertSame('Test wd under score.jpg', $row['file']);
    }

    public function testAP18ThatIsNotAStillIsRefusedBeforeAnyDownload(): void
    {
        $sent = [];
        $this->handle('Q99999904', $this->claims('Test wd tour.ogv'), $sent);

        $row = $this->images->find('Q99999904');
        self::assertNotNull($row);
        self::assertTrue($row['answered']);
        self::assertNull($row['file'], 'a video is not a photo');
        self::assertCount(0, $sent, 'and nothing is queued for it');
    }

    public function testWikidataBeingDownLetsGoOfTheClaim(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 503]));
        $this->handle('Q99999905', $client);

        self::assertNull($this->images->find('Q99999905'),
            'an unanswered claim is dropped, so a later visit asks again instead of spinning forever');
    }

    /** @param list<object> $sent */
    private function handle(string $qid, MockHttpClient $client, array &$sent = [], ?PhotoPlace $place = null): void
    {
        $this->images->claim($qid);

        $bus = new class($sent) implements MessageBusInterface {
            /** @param list<object> $sent */
            public function __construct(private array &$sent)
            {
            }

            #[\Override]
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sent[] = $message;

                return new Envelope($message);
            }
        };

        /** @var MediaStorage $storage */
        $storage = self::getContainer()->get(MediaStorage::class);
        $handler = new ResolveWikidataImageHandler(
            new CommonsApi($client, 'CyclingCommons-test/1.0'),
            $this->images,
            new CommonsPhotoAdmission($this->photos, $storage, $bus),
            new NullLogger(),
        );
        $handler(ResolveWikidataImage::forPlace($qid, 'EU', $place ?? PhotoPlace::unplaced()));
    }

    private function claims(?string $file): MockHttpClient
    {
        $body = null === $file
            ? json_encode(['claims' => []], \JSON_THROW_ON_ERROR)
            : json_encode(['claims' => ['P18' => [['mainsnak' => ['datavalue' => ['value' => $file]]]]]], \JSON_THROW_ON_ERROR);

        return new MockHttpClient(static fn (): MockResponse => new MockResponse($body, ['http_code' => 200]));
    }
}
