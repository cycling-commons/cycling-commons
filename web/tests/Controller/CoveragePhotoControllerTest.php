<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Media\Message\FetchCommonsPhoto;
use App\Tests\Coverage\CoverageSchema;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The endpoint takes a POI, never a filename. That is the whole security
 * argument: a client that could name a file could make us download anything on
 * Commons, and a POI ref can only name a row we already hold.
 */
final class CoveragePhotoControllerTest extends WebTestCase
{
    use CoverageSchema;

    public function testPoiWithNoCommonsFileReportsNone(): void
    {
        $client = $this->browser();
        $id = $this->seed(['tourism' => 'viewpoint']);

        $client->request('GET', "/map/coverage/photo/node/{$id}");

        self::assertResponseIsSuccessful();
        self::assertSame('none', $this->payload($client)['state']);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testFirstVisitClaimsAndDispatchesExactlyOnce(): void
    {
        $client = $this->browser();
        $id = $this->seed(['tourism' => 'viewpoint', 'wikimedia_commons' => 'File:Test endpoint.jpg']);

        $client->request('GET', "/map/coverage/photo/node/{$id}");
        self::assertSame('pending', $this->payload($client)['state']);

        $client->request('GET', "/map/coverage/photo/node/{$id}");
        self::assertSame('pending', $this->payload($client)['state']);

        // The claim is what guarantees one download, and it is a database fact
        // rather than a transport one: ON CONFLICT DO NOTHING decides which of
        // two simultaneous readers dispatches. Asserting the row count tests
        // exactly that, and survives the kernel reboots a browser test does.
        /** @var Connection $db */
        $db = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertSame(1, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM commons_photo WHERE file = :f',
            ['f' => 'Test endpoint.jpg'],
        ), 'a second reader must not create a second row, and so cannot queue a second download');
    }

    /** The fetch is judged against the place that asked, so the place rides along. */
    public function testTheFetchCarriesThePlaceThatAsked(): void
    {
        $client = $this->browser();
        $id = $this->seed(['tourism' => 'viewpoint', 'wikimedia_commons' => 'File:Test place.jpg']);

        $client->request('GET', "/map/coverage/photo/node/{$id}");

        /** @var \Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $sent = array_values(array_filter(
            array_map(static fn ($e): object => $e->getMessage(), $transport->getSent()),
            static fn (object $m): bool => $m instanceof FetchCommonsPhoto && 'Test place.jpg' === $m->file,
        ));
        self::assertCount(1, $sent);
        self::assertInstanceOf(FetchCommonsPhoto::class, $sent[0]);
        self::assertSame('P', $sent[0]->letter);
        self::assertEqualsWithDelta(50.55, $sent[0]->lat, 0.00001);
        self::assertEqualsWithDelta(5.55, $sent[0]->lng, 0.00001);
    }

    /**
     * A file declined for a scenic view is not asked for again by that view:
     * the refusal is already known from the row, with no download and no
     * second Commons call. A place that may show it reopens it.
     */
    public function testADeclinedFileStaysDeclinedUntilAPlaceThatMayShowItAsks(): void
    {
        $client = $this->browser();

        $scenic = $this->seed(['tourism' => 'viewpoint', 'wikimedia_commons' => 'File:Test declined.jpg']);
        $this->declinedPhoto('Test declined.jpg', [50.5536, 5.55]);
        $client->request('GET', "/map/coverage/photo/node/{$scenic}");
        self::assertSame(['state' => 'none'], $this->payload($client));
        self::assertSame('declined', $this->state('Test declined.jpg'), 'the scenic view does not reopen it');

        $castle = $this->seed(['historic' => 'castle', 'wikimedia_commons' => 'File:Test declined.jpg', 'name' => 'x'], 'Q');
        $this->declinedPhoto('Test declined.jpg', [50.5536, 5.55]);
        $client->request('GET', "/map/coverage/photo/node/{$castle}");
        self::assertSame('pending', $this->payload($client)['state']);
        self::assertSame('pending', $this->state('Test declined.jpg'), 'a castle may show it, so it is fetched');
    }

    /**
     * A scenic POI shows a photo only when its camera stood near the pin
     * (PhotoValidator). A ready photo with no camera point, or one taken
     * 400 m away, answers exactly like a POI with no photo at all.
     */
    public function testAScenicPhotoWithNoCameraNearThePinIsNotShown(): void
    {
        $client = $this->browser();

        $none = $this->seed(['tourism' => 'viewpoint', 'wikimedia_commons' => 'File:Test no camera.jpg']);
        $this->readyPhoto('Test no camera.jpg', null);
        $client->request('GET', "/map/coverage/photo/node/{$none}");
        self::assertResponseIsSuccessful();
        self::assertSame(['state' => 'none'], $this->payload($client), 'no camera point is no photo');

        // The seeded pin is 50.55, 5.55; 0.0036 degrees north is about 400 m.
        $far = $this->seed(['tourism' => 'viewpoint', 'wikimedia_commons' => 'File:Test far camera.jpg']);
        $this->readyPhoto('Test far camera.jpg', [50.5536, 5.55]);
        $client->request('GET', "/map/coverage/photo/node/{$far}");
        self::assertSame(['state' => 'none'], $this->payload($client), 'a camera 400 m away is not the view from here');

        $near = $this->seed(['tourism' => 'viewpoint', 'wikimedia_commons' => 'File:Test near camera.jpg']);
        $this->readyPhoto('Test near camera.jpg', [50.5509, 5.55]);
        $client->request('GET', "/map/coverage/photo/node/{$near}");
        $payload = $this->payload($client);
        self::assertSame('ready', $payload['state']);
        self::assertSame([50.5509, 5.55], $payload['cameraAt']);
    }

    public function testANonScenicPoiShowsItsPhotoWhereverTheCameraStood(): void
    {
        $client = $this->browser();
        $id = $this->seed(['historic' => 'castle', 'wikimedia_commons' => 'File:Test castle.jpg'], 'Q');
        $this->readyPhoto('Test castle.jpg', null);

        $client->request('GET', "/map/coverage/photo/node/{$id}");
        self::assertSame('ready', $this->payload($client)['state']);
    }

    /**
     * A served item standing for the POI is what the map draws and what the
     * drawer opens, so the photo is judged against the item's pin and letter,
     * not the coverage row's. Here the item's pin stands 400 m north of the
     * OSM point: a camera at the OSM point is not the view from the item.
     */
    public function testAPhotoIsJudgedAgainstThePinOfTheItemStandingForThePoi(): void
    {
        $client = $this->browser();
        /** @var Connection $db */
        $db = self::getContainer()->get('doctrine.dbal.default_connection');

        $far = $this->seed(['tourism' => 'viewpoint', 'wikimedia_commons' => 'File:Test item far.jpg']);
        $near = $this->seed(['tourism' => 'viewpoint', 'wikimedia_commons' => 'File:Test item near.jpg']);
        // After both seeds: seed() clears every test photo.
        $this->readyPhoto('Test item far.jpg', [50.55, 5.55]);
        $this->readyPhoto('Test item near.jpg', [50.5536, 5.55]);
        foreach ([$far, $near] as $id) {
            $db->executeStatement(
                "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, osm_ref, attributes, created_at, updated_at)
                 VALUES ('P', 'Moved viewpoint', ST_GeomFromText('POINT(5.55 50.5536)', 4326), 'BE', 'verified', 'osm', :r, :r, '{}', now(), now())",
                ['r' => 'node/'.$id],
            );
        }

        $client->request('GET', "/map/coverage/photo/node/{$far}");
        self::assertSame(['state' => 'none'], $this->payload($client), 'the camera stood at the OSM point, 400 m from the item pin');

        $client->request('GET', "/map/coverage/photo/node/{$near}");
        self::assertSame('ready', $this->payload($client)['state'], 'the camera stood at the item pin');
    }

    public function testUnknownRefIs404(): void
    {
        $client = $this->browser();
        $client->request('GET', '/map/coverage/photo/node/999999999999');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDetailAdvertisesWhetherAPhotoIsPossible(): void
    {
        $client = $this->browser();
        $with = $this->seed(['tourism' => 'viewpoint', 'wikimedia_commons' => 'File:Test advertise.jpg']);
        $without = $this->seed(['tourism' => 'viewpoint', 'wikimedia_commons' => 'Category:Ardennes']);

        $client->request('GET', "/map/coverage/poi/node/{$with}");
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload($client)['photo']);

        $client->request('GET', "/map/coverage/poi/node/{$without}");
        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload($client)['photo'], 'a category is not a photo');
    }

    /** A client whose test database actually has the pipeline-owned table. */
    private function browser(): KernelBrowser
    {
        $client = static::createClient();
        // One kernel across both requests, so the in-memory transport that the
        // dispatch assertion reads is the same one the requests wrote to. A
        // reboot between requests resets it and the count reads as zero.
        $client->disableReboot();
        /** @var Connection $db */
        $db = self::getContainer()->get('doctrine.dbal.default_connection');
        self::ensureCoverageSchema($db);

        return $client;
    }

    /** @return array<string, mixed> */
    private function payload(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    /** @param array{0: float, 1: float}|null $camera */
    private function readyPhoto(string $file, ?array $camera): void
    {
        /** @var Connection $db */
        $db = self::getContainer()->get('doctrine.dbal.default_connection');
        $db->executeStatement(
            "INSERT INTO commons_photo (file, state, credit, license, storage_bucket, storage_prefix, width, height,
                                        requested_at, ready_at, camera_lat, camera_lng, camera_checked_at)
             VALUES (:f, 'ready', 'Somebody', 'CC BY-SA 4.0', 'test-bucket-eu-01', 'published/test/cafe', 1400, 933,
                     NOW(), NOW(), :lat, :lng, NOW())",
            ['f' => $file, 'lat' => $camera[0] ?? null, 'lng' => $camera[1] ?? null],
        );
    }

    /** @param array{0: float, 1: float} $camera */
    private function declinedPhoto(string $file, array $camera): void
    {
        /** @var Connection $db */
        $db = self::getContainer()->get('doctrine.dbal.default_connection');
        $db->executeStatement('DELETE FROM commons_photo WHERE file = :f', ['f' => $file]);
        $db->executeStatement(
            "INSERT INTO commons_photo (file, state, failed_reason, credit, license, requested_at, camera_lat, camera_lng, camera_checked_at)
             VALUES (:f, 'declined', 'camera_far', 'Somebody', 'CC BY-SA 4.0', NOW(), :lat, :lng, NOW())",
            ['f' => $file, 'lat' => $camera[0], 'lng' => $camera[1]],
        );
    }

    private function state(string $file): string
    {
        /** @var Connection $db */
        $db = self::getContainer()->get('doctrine.dbal.default_connection');

        return (string) $db->fetchOne('SELECT state FROM commons_photo WHERE file = :f', ['f' => $file]);
    }

    /** @param array<string, string> $tags */
    private function seed(array $tags, string $letter = 'P'): int
    {
        /** @var Connection $db */
        $db = self::getContainer()->get('doctrine.dbal.default_connection');
        $id = 800000000 + abs(crc32(json_encode($tags, \JSON_THROW_ON_ERROR))) % 90000000;
        $ref = 'node/'.$id;
        // Only this ref. ensureCoverageSchema() truncates the table, so calling
        // it here would wipe a row an earlier seed() in the same test just made.
        $db->executeStatement('DELETE FROM coverage_poi WHERE ref = :r', ['r' => $ref]);
        $db->executeStatement('DELETE FROM commons_photo WHERE file LIKE :f', ['f' => 'Test %']);
        // ContinentResolver reads region -> world_country -> world_continent, and
        // the test database ships the last two but no regions. Without one, the
        // endpoint correctly answers `none`: no continent means no bucket.
        $db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, admin_level, created_at, updated_at)
             VALUES ('test-photo-region', 'Test photo region',
                     ST_SetSRID(ST_MakeEnvelope(5.0, 50.0, 6.0, 51.0), 4326), 100, 'BE', 4, NOW(), NOW())
             ON CONFLICT DO NOTHING",
        );
        $db->executeStatement(
            "INSERT INTO coverage_poi (ref, letter, name, geom, tags, country_code)
             VALUES (:r, :l, NULL, ST_SetSRID(ST_MakePoint(5.55, 50.55), 4326), CAST(:t AS jsonb), 'BE')",
            ['r' => $ref, 'l' => $letter, 't' => json_encode($tags, \JSON_THROW_ON_ERROR)],
        );

        return $id;
    }
}
