<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\Command\LocaliseCommonsPhotosCommand;
use App\Media\Commons\CommonsApi;
use App\Media\Commons\CommonsPhotoAdmission;
use App\Media\Commons\CommonsPhotoRepository;
use App\Media\Commons\CommonsPhotoState;
use App\Media\ContinentResolver;
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
 * The backfill that ends the catalogue's Commons hotlinks.
 *
 * Two things are worth pinning and neither is "it downloads a file". First,
 * the attribution: CC BY-SA is satisfied only while credit, the uploader and
 * the licence travel with our copy, so a localised row that lost them would be
 * a licence breach that looks like a working page. Second, the refusal: a file
 * the licence gate rejects must keep its hotlink rather than be copied into our
 * bucket, because linking is not republishing and only one of the two needs
 * permission.
 */
final class LocaliseCommonsPhotosCommandTest extends KernelTestCase
{
    private const string FILE = 'Localise test subject.jpg';
    private const string HOTLINK = 'https://commons.wikimedia.org/wiki/Special:FilePath/Localise_test_subject.jpg';

    private Connection $db;
    private CommonsPhotoRepository $photos;
    private int $itemId;

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

        $this->db->executeStatement('DELETE FROM commons_photo WHERE file = :f', ['f' => self::FILE]);
        $this->itemId = $this->seedHotlinkedItem();
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement('DELETE FROM item WHERE id = :id', ['id' => $this->itemId]);
        $this->db->executeStatement('DELETE FROM commons_photo WHERE file = :f', ['f' => self::FILE]);
        parent::tearDown();
    }

    public function testAHotlinkBecomesOursAndKeepsItsAttribution(): void
    {
        $tester = $this->localise('CC BY-SA 3.0');
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        $photo = $this->storedPhoto();
        // The command's own report on failure: it names which of the five
        // outcomes the row took, which is the whole question when this breaks.
        self::assertStringNotContainsString(
            'wikimedia.org',
            (string) $photo['sm'],
            "the small variant must be ours now.\n".$tester->getDisplay(),
        );
        self::assertStringNotContainsString('wikimedia.org', (string) $photo['lg']);
        self::assertStringEndsWith('/sm.webp', (string) $photo['sm']);

        // The licence half. Losing any of these turns a working page into a
        // licence breach, and nothing on screen would say so.
        self::assertSame('CC BY-SA 3.0', $photo['license']);
        self::assertNotSame('', (string) $photo['credit']);
        self::assertStringContainsString('commons.wikimedia.org/wiki/User:', (string) $photo['creditUrl']);
        self::assertStringContainsString('commons.wikimedia.org/wiki/File:', (string) $photo['source']);

        // 'state' belongs to the poll the drawer runs, never to a stored photo.
        self::assertArrayNotHasKey('state', $photo);
    }

    public function testAHandWrittenAltSurvivesTheRewrite(): void
    {
        $this->db->executeStatement(
            "UPDATE item SET attributes = jsonb_set(attributes, '{photo,alt}', '\"A climb in the rain\"') WHERE id = :id",
            ['id' => $this->itemId],
        );

        $this->localise('CC BY-SA 3.0');

        self::assertSame('A climb in the rain', $this->storedPhoto()['alt'] ?? null);
    }

    public function testAnUnfreeFileKeepsItsHotlink(): void
    {
        $tester = $this->localise('Fair use');

        $photo = $this->storedPhoto();
        self::assertSame(self::HOTLINK, $photo['sm'], 'a file we may not republish must stay a link');
        self::assertSame(CommonsPhotoState::Unusable->value, $this->photos->find(self::FILE)['state'] ?? null);
        self::assertStringContainsString('refused (licence)', $tester->getDisplay());
    }

    /** Not every refusal is a licence refusal, and the report says which it was. */
    public function testAFileWithNoAuthorIsReportedAsSuch(): void
    {
        $tester = $this->localise('CC BY-SA 3.0', artist: '');

        self::assertSame(self::HOTLINK, $this->storedPhoto()['sm']);
        self::assertStringContainsString('refused (no_author)', $tester->getDisplay());
        self::assertStringNotContainsString('refused (licence)', $tester->getDisplay());
    }

    /**
     * A scenic view gets our copy only of a photo taken at its pin
     * (PhotoValidator). A file whose camera stood 400 m away is not
     * downloaded for it and nothing is written onto the item.
     */
    public function testAScenicViewDoesNotLocaliseAPhotoTakenElsewhere(): void
    {
        $this->db->executeStatement("UPDATE item SET letter = 'P' WHERE id = :id", ['id' => $this->itemId]);
        $downloads = 0;

        $tester = $this->localise('CC BY-SA 3.0', [], $downloads, camera: [50.5036, 5.5003]);

        self::assertSame(0, $downloads);
        self::assertSame(self::HOTLINK, $this->storedPhoto()['sm'], 'nothing is written onto the item');
        self::assertSame(CommonsPhotoState::Declined->value, $this->photos->find(self::FILE)['state'] ?? null);
        self::assertStringContainsString('camera_far', $tester->getDisplay());
    }

    public function testAScenicViewLocalisesAPhotoTakenAtItsPin(): void
    {
        $this->db->executeStatement("UPDATE item SET letter = 'P' WHERE id = :id", ['id' => $this->itemId]);

        $this->localise('CC BY-SA 3.0', camera: [50.5009, 5.5003]);

        $photo = $this->storedPhoto();
        self::assertStringNotContainsString('wikimedia.org', (string) $photo['sm']);
        self::assertSame([50.5009, 5.5003], $photo['cameraAt']);
    }

    /**
     * A refusal is only final while our list of accepted licences is.
     *
     * `unusable` is terminal because the verdict is about the file. That holds
     * only until the list moves, and it does: the first real backfill refused
     * nine photos that were all freely licensed, under names LicenceUrls simply
     * did not carry yet. Without a way to re-ask they would have stayed
     * hotlinked forever, with the report calmly saying they were not ours.
     */
    public function testRecheckLicencesReopensAPastRefusal(): void
    {
        $this->localise('Fair use');
        self::assertSame(CommonsPhotoState::Unusable->value, $this->photos->find(self::FILE)['state']);
        self::assertSame(self::HOTLINK, $this->storedPhoto()['sm']);

        // Same file, and this time the gate says yes: exactly what adding a
        // licence name to LicenceUrls does.
        $this->localise('CC BY-SA 3.0', ['--recheck-licences' => true]);

        self::assertStringNotContainsString('wikimedia.org', (string) $this->storedPhoto()['sm']);
    }

    /** A dry run must not forget anything either. */
    public function testRecheckLicencesIsInertUnderDryRun(): void
    {
        $this->localise('Fair use');

        $this->localise('CC BY-SA 3.0', ['--recheck-licences' => true, '--dry-run' => true]);

        self::assertSame(
            CommonsPhotoState::Unusable->value,
            $this->photos->find(self::FILE)['state'],
            'a dry run that quietly cleared verdicts would not be a dry run',
        );
    }

    public function testDryRunWritesNothing(): void
    {
        $this->localise('CC BY-SA 3.0', ['--dry-run' => true]);

        self::assertSame(self::HOTLINK, $this->storedPhoto()['sm']);
        self::assertNull($this->photos->find(self::FILE), 'a dry run must not even claim the file');
    }

    public function testAFileWeAlreadyHoldIsReusedRatherThanRefetched(): void
    {
        $downloads = 0;
        $this->localise('CC BY-SA 3.0', [], $downloads);
        self::assertSame(1, $downloads);

        // Put the row back the way it was: the same file, hotlinked again, as
        // a second item pointing at a photo we now hold would be.
        $this->db->executeStatement(
            "UPDATE item SET attributes = jsonb_set(attributes, '{photo,sm}', :u::jsonb) WHERE id = :id",
            ['u' => json_encode(self::HOTLINK, \JSON_THROW_ON_ERROR), 'id' => $this->itemId],
        );

        $this->localise('CC BY-SA 3.0', [], $downloads);
        self::assertSame(1, $downloads, 'a file already in our storage must not be fetched twice');
        self::assertStringNotContainsString('wikimedia.org', (string) $this->storedPhoto()['sm']);
    }

    /**
     * The pause between fetches defaults to polite, not to none.
     *
     * Wikimedia gives its bandwidth away and asks clients to come one at a time.
     * A backfill of several hundred files is exactly the shape of request that
     * abuses that, and the failure mode of getting it wrong is a 429 or a block
     * on the whole site, not a red test. So the default is the safe one and
     * speeding it up has to be typed out on purpose.
     */
    public function testTheFetchPauseDefaultsToOneSecond(): void
    {
        $command = new LocaliseCommonsPhotosCommand(
            $this->db,
            $this->photos,
            self::getContainer()->get(CommonsPhotoAdmission::class),
            $this->nullHandler(),
            self::getContainer()->get(ContinentResolver::class),
        );

        $sleep = $command->getDefinition()->getOption('sleep');
        self::assertNull($sleep->getDefault(), 'unset means the command picks the polite default itself');
        self::assertStringContainsString('1000', $sleep->getDescription(), 'the default has to be discoverable from --help');
    }

    /** A handler that would explode if called: this test never fetches. */
    private function nullHandler(): FetchCommonsPhotoHandler
    {
        $container = self::getContainer();
        /** @var PhotoProcessor $processor */
        $processor = $container->get(PhotoProcessor::class);

        return new FetchCommonsPhotoHandler(
            new CommonsApi(new MockHttpClient([]), 'CyclingCommons-test/1.0'),
            $this->photos,
            $processor,
            $container->get(MediaStorage::class),
            $this->scanner(),
            new XmpRights('https://cyclingcommons.example'),
            new NullLogger(),
        );
    }

    /**
     * The gallery is the shape that got missed first time round.
     *
     * `photos` is where a rider's uploads live, and
     * `SeedManualCatalogCommand` used it for the hand-curated multi-photo
     * climbs too, so it holds Commons hotlinks as well. A version of this
     * command that read only the singular `photo` left five photos on four
     * items hotlinked and reported success.
     */
    public function testAGalleryEntryIsLocalisedToo(): void
    {
        $this->giveItAGallery();

        $this->localise('CC BY-SA 3.0');

        $gallery = $this->storedGallery();
        self::assertCount(2, $gallery);
        self::assertStringNotContainsString('wikimedia.org', (string) $gallery[0]['sm'], 'the hotlinked gallery entry must be ours now');
        self::assertSame('CC BY-SA 3.0', $gallery[0]['license']);
        self::assertStringContainsString('commons.wikimedia.org/wiki/File:', (string) $gallery[0]['source']);
    }

    /**
     * A rider's own photo sits in the same array and must not be touched: it is
     * already ours, and it carries no Commons attribution to overwrite.
     */
    public function testARiderUploadInTheSameGalleryIsLeftAlone(): void
    {
        $this->giveItAGallery();

        $this->localise('CC BY-SA 3.0');

        $mine = $this->storedGallery()[1];
        self::assertSame('http://media.test/img/eu-01/published/rider/abc/sm.webp', $mine['sm']);
        self::assertSame('XanderK', $mine['credit']);
        self::assertArrayNotHasKey('creditUrl', $mine, 'a rider must never be credited with a link to somebody else\'s Commons page');
        self::assertArrayNotHasKey('source', $mine);
    }

    /** One hotlinked Commons photo and one rider upload, in that order. */
    private function giveItAGallery(): void
    {
        $gallery = json_encode([
            [
                'sm' => self::HOTLINK,
                'lg' => self::HOTLINK.'?width=1400',
                'credit' => 'Somebody Else',
                'creditUrl' => 'https://commons.wikimedia.org/wiki/User:Somebody_Else',
                'license' => 'CC BY-SA 3.0',
                'source' => 'https://commons.wikimedia.org/wiki/File:Localise_test_subject.jpg',
            ],
            [
                'id' => 'aaaaaaaa-0000-4000-8000-000000000000',
                'sm' => 'http://media.test/img/eu-01/published/rider/abc/sm.webp',
                'lg' => 'http://media.test/img/eu-01/published/rider/abc/lg.webp',
                'credit' => 'XanderK',
                'license' => 'CC BY-SA 4.0',
                'takenAt' => '2026-07',
            ],
        ], \JSON_THROW_ON_ERROR);

        $this->db->executeStatement(
            "UPDATE item SET attributes = jsonb_set(attributes, '{photos}', :g::jsonb) WHERE id = :id",
            ['g' => $gallery, 'id' => $this->itemId],
        );
    }

    /** @return list<array<string, mixed>> */
    private function storedGallery(): array
    {
        $raw = $this->db->fetchOne("SELECT attributes->'photos' FROM item WHERE id = :id", ['id' => $this->itemId]);
        /** @var list<array<string, mixed>> $gallery */
        $gallery = json_decode((string) $raw, true, 512, \JSON_THROW_ON_ERROR);

        return $gallery;
    }

    /**
     * @param array<string, mixed>           $options
     * @param array{0: float, 1: float}|null $camera
     */
    private function localise(string $licence, array $options = [], int &$downloads = 0, string $artist = '<a href="//commons.wikimedia.org/wiki/User:Somebody_Else">Somebody Else</a>', ?array $camera = null): CommandTester
    {
        $container = self::getContainer();
        /** @var PhotoProcessor $processor */
        $processor = $container->get(PhotoProcessor::class);
        /** @var MediaStorage $storage */
        $storage = $container->get(MediaStorage::class);
        /** @var CommonsPhotoAdmission $admission */
        $admission = $container->get(CommonsPhotoAdmission::class);
        /** @var ContinentResolver $continents */
        $continents = $container->get(ContinentResolver::class);

        $handler = new FetchCommonsPhotoHandler(
            new CommonsApi($this->client($licence, $downloads, $artist, $camera), 'CyclingCommons-test/1.0'),
            $this->photos,
            $processor,
            $storage,
            $this->scanner(),
            new XmpRights('https://cyclingcommons.example'),
            new NullLogger(),
        );

        $command = new LocaliseCommonsPhotosCommand($this->db, $this->photos, $admission, $handler, $continents);
        $application = new Application(self::$kernel);
        $application->addCommand($command);

        $tester = new CommandTester($application->find('app:media:localise-commons'));
        // --sleep=0: the polite pause is for Wikimedia, not for the suite.
        $tester->execute($options + ['--limit' => '50', '--sleep' => '0']);

        return $tester;
    }

    /** @return array<string, mixed> */
    private function storedPhoto(): array
    {
        $raw = $this->db->fetchOne("SELECT attributes->'photo' FROM item WHERE id = :id", ['id' => $this->itemId]);
        /** @var array<string, mixed> $photo */
        $photo = json_decode((string) $raw, true, 512, \JSON_THROW_ON_ERROR);

        return $photo;
    }

    /** A single letter-Q row in Belgium, carrying exactly the shape the seeders wrote. */
    private function seedHotlinkedItem(): int
    {
        $photo = json_encode([
            'sm' => self::HOTLINK,
            'lg' => self::HOTLINK.'?width=1400',
            'credit' => 'Somebody Else',
            'creditUrl' => 'https://commons.wikimedia.org/wiki/User:Somebody_Else',
            'license' => 'CC BY-SA 3.0',
            'source' => 'https://commons.wikimedia.org/wiki/File:Localise_test_subject.jpg',
        ], \JSON_THROW_ON_ERROR);

        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at)
                VALUES ('Q', 'Localise test place', ST_SetSRID(ST_MakePoint(5.5, 50.5), 4326), 'BE', 'unverified',
                        'manual', 'localise-test', jsonb_build_object('photo', :photo::jsonb), NOW(), NOW())
                SQL,
            ['photo' => $photo],
        );

        return (int) $this->db->lastInsertId('item_id_seq');
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

    /** @param array{0: float, 1: float}|null $camera */
    private function client(string $licence, int &$downloads, string $artist, ?array $camera): MockHttpClient
    {
        $page = ['imageinfo' => [[
            'thumburl' => 'https://thumb.wikimedia.org/thumb/Localise_test_subject.jpg',
            'extmetadata' => [
                'LicenseShortName' => ['value' => $licence],
                'Artist' => ['value' => $artist],
            ],
        ]]];
        if (null !== $camera) {
            $page['coordinates'] = [['lat' => $camera[0], 'lon' => $camera[1], 'primary' => true, 'type' => 'camera']];
        }
        $metadata = json_encode(['query' => ['pages' => [$page]]], \JSON_THROW_ON_ERROR);

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
        $image->newImage(1400, 933, 'blue');
        $image->setImageFormat('jpeg');
        $bytes = $image->getImageBlob();
        $image->clear();

        return $bytes;
    }
}
