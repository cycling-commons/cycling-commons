<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Controller;

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

    /** @param array<string, string> $tags */
    private function seed(array $tags): int
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
             VALUES (:r, 'I', NULL, ST_SetSRID(ST_MakePoint(5.55, 50.55), 4326), CAST(:t AS jsonb), 'BE')",
            ['r' => $ref, 't' => json_encode($tags, \JSON_THROW_ON_ERROR)],
        );

        return $id;
    }
}
