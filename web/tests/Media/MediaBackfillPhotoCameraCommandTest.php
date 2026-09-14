<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\Command\MediaBackfillPhotoCameraCommand;
use App\Media\Commons\CommonsApi;
use App\Media\Commons\CommonsPhotoRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Where the camera stood, for photos stored before anybody asked.
 *
 * A scenic view hides every photo whose camera position is unknown
 * (ScenicPhotoRule), so without this backfill every photo fetched before the
 * camera columns existed would vanish from the scenic layer, including the ones
 * taken right at the pin.
 */
final class MediaBackfillPhotoCameraCommandTest extends KernelTestCase
{
    private const string WITH_CAMERA = 'Backfill camera near.jpg';
    private const string WITHOUT_CAMERA = 'Backfill camera none.jpg';
    private const string RIDER = 'bbbbbbbb-0000-4000-8000-000000000001';

    private Connection $db;
    private int $itemId;
    private int $requests = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var Connection $db */
        $db = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->db = $db;

        foreach ([self::WITH_CAMERA, self::WITHOUT_CAMERA] as $file) {
            $this->db->executeStatement(
                "INSERT INTO commons_photo (file, state, credit, license, storage_bucket, storage_prefix, width, height, requested_at, ready_at)
                 VALUES (:f, 'ready', 'Somebody', 'CC BY-SA 4.0', 'test-bucket-eu-01', :p, 1400, 933, NOW(), NOW())",
                ['f' => $file, 'p' => 'published/backfill/'.md5($file)],
            );
        }

        $consent = 'bbbbbbbb-0000-4000-8000-0000000000c0';
        $this->db->executeStatement(
            "INSERT INTO consent_record (id, user_id, kind, version, text_hash, consented_at) VALUES (:id, 1, 'media-cc-by-sa', 'test', 'x', NOW())",
            ['id' => $consent],
        );
        $this->db->executeStatement(
            "INSERT INTO media_upload (id, user_id, consent_record_id, continent, status, width, height, bytes, gps_distance_m, created_at, storage_bucket)
             VALUES (:id, 1, :c, 'EU', 'approved', 1200, 900, 4242, 35, NOW(), 'test-bucket-eu-01')",
            ['id' => self::RIDER, 'c' => $consent],
        );

        // The pin is 50.4, 5.8. Both Commons entries name their file the way
        // readyPhoto() writes `source`; the third is a rider's upload.
        $this->itemId = (int) $this->db->fetchOne(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('P', 'Backfill camera view', ST_GeomFromText('POINT(5.8 50.4)', 4326), 'BE', 'verified', 'manual', 'manual:backfill-camera',
                     CAST(:a AS jsonb), NOW() - interval '1 day', NOW() - interval '1 day')
             RETURNING id",
            ['a' => json_encode(['photos' => [
                ['sm' => 'https://img.test/near.webp', 'source' => 'https://commons.wikimedia.org/wiki/File:Backfill_camera_near.jpg'],
                ['sm' => 'https://img.test/none.webp', 'source' => 'https://commons.wikimedia.org/wiki/File:'.rawurlencode('Backfill_camera_none.jpg'), 'cameraAt' => [1.0, 1.0]],
                ['id' => self::RIDER, 'sm' => 'https://img.test/rider.webp'],
            ]], \JSON_THROW_ON_ERROR)],
        );
    }

    public function testADryRunAsksButWritesNothing(): void
    {
        $tester = $this->backfill([]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        self::assertGreaterThan(0, $this->requests, 'a dry run still asks Commons, which is only reading');
        self::assertNull($this->checkedAt(self::WITH_CAMERA), 'nothing is recorded without --write');
        self::assertStringContainsString('Dry run', $tester->getDisplay());
        self::assertArrayNotHasKey('distanceM', $this->gallery()[2]);
    }

    public function testWriteRecordsEveryAnswerAndStampsTheItem(): void
    {
        $tester = $this->backfill(['--write' => true]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        $photos = self::getContainer()->get(CommonsPhotoRepository::class);
        self::assertSame(50.40012, $photos->find(self::WITH_CAMERA)['camera_lat'] ?? null);
        self::assertNotNull($this->checkedAt(self::WITH_CAMERA));
        $none = $photos->find(self::WITHOUT_CAMERA);
        self::assertNotNull($none);
        self::assertNull($none['camera_lat']);
        self::assertNotNull($this->checkedAt(self::WITHOUT_CAMERA), '"no camera" is an answer and is remembered');

        $gallery = $this->gallery();
        self::assertSame([50.40012, 5.80034], $gallery[0]['cameraAt'] ?? null);
        self::assertArrayNotHasKey('cameraAt', $gallery[1], 'a stale camera the file no longer has is removed');
        self::assertSame(35, $gallery[2]['distanceM'] ?? null, 'a rider photo gets its distance from the upload row');

        // A second run has nothing left to ask.
        $this->requests = 0;
        $this->backfill(['--write' => true]);
        self::assertSame(0, $this->requests);
    }

    public function testCommonsBeingDownLeavesTheRowsUnchecked(): void
    {
        $tester = $this->backfill(['--write' => true], down: true);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        self::assertNull($this->checkedAt(self::WITH_CAMERA), 'an outage is not an answer');
        self::assertArrayNotHasKey('cameraAt', $this->gallery()[0]);
    }

    /** @param array<string, mixed> $options */
    private function backfill(array $options, bool $down = false): CommandTester
    {
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use ($down): MockResponse {
            ++$this->requests;
            if ($down) {
                return new MockResponse('', ['http_code' => 503]);
            }
            $body = \is_string($opts['body'] ?? null) ? $opts['body'] : '';
            parse_str($body, $form);
            $titles = explode('|', \is_string($form['titles'] ?? null) ? $form['titles'] : '');
            $pages = [];
            foreach ($titles as $title) {
                $page = ['title' => $title];
                if ('File:'.self::WITH_CAMERA === $title) {
                    $page['coordinates'] = [['lat' => 50.40012, 'lon' => 5.80034, 'primary' => true, 'type' => 'camera']];
                }
                $pages[] = $page;
            }

            return new MockResponse(json_encode(['query' => ['pages' => $pages]], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        $container = self::getContainer();
        /** @var CommonsPhotoRepository $photos */
        $photos = $container->get(CommonsPhotoRepository::class);
        $command = new MediaBackfillPhotoCameraCommand($this->db, $photos, new CommonsApi($client, 'CyclingCommons-test/1.0'));
        $application = new Application(self::$kernel);
        $application->addCommand($command);

        $tester = new CommandTester($application->find('app:media:backfill-photo-camera'));
        // --sleep=0: the polite pause is for Wikimedia, not for the suite.
        $tester->execute($options + ['--sleep' => '0']);

        return $tester;
    }

    private function checkedAt(string $file): ?string
    {
        $at = $this->db->fetchOne('SELECT camera_checked_at FROM commons_photo WHERE file = :f', ['f' => $file]);

        return \is_string($at) ? $at : null;
    }

    /** @return list<array<string, mixed>> */
    private function gallery(): array
    {
        $raw = $this->db->fetchOne("SELECT attributes->'photos' FROM item WHERE id = :id", ['id' => $this->itemId]);
        /** @var list<array<string, mixed>> $gallery */
        $gallery = json_decode((string) $raw, true, 512, \JSON_THROW_ON_ERROR);

        return $gallery;
    }
}
