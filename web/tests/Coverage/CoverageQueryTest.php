<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Coverage;

use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /map/coverage/{search,nearby,counts}
 * (coverage-provider.md §5): the anonymous coverage query
 * plane — curated-first ranking, ref dedupe (osm-data-architecture.md §8),
 * community cap, coverage_read limiter, public HTTP caching.
 */
final class CoverageQueryTest extends WebTestCase
{
    use CoverageSchema;

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    /** A served canonical point item (the curated tier). */
    private function item(string $sourceRef, string $name, float $lat = 50.4, float $lng = 5.8, string $letter = 'C'): Item
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = (new Item())->setLetter($letter)->setName($name)
            ->setGeom(json_encode(['type' => 'Point', 'coordinates' => [$lng, $lat]], \JSON_THROW_ON_ERROR))
            ->setCountryCode('BE')->setState(ItemState::Verified)->setSource(ItemSource::Osm)
            ->setSourceRef($sourceRef)->setAttributes([]);
        $em->persist($item);
        $em->flush();

        return $item;
    }

    /** @return array<string, mixed> */
    private function getJson(KernelBrowser $client, string $url): array
    {
        $client->request('GET', $url);

        return json_decode((string) $client->getResponse()->getContent(), true);
    }

    public function testSearchCuratedFirstAndDedupedByRef(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        // The same OSM object exists in both tiers: coverage must be suppressed.
        self::insertCoveragePoi($db, ['ref' => 'node/9001', 'name' => 'Fontaine du Parc']);
        self::insertCoveragePoi($db, ['ref' => 'node/9002', 'name' => 'Fontaine Neuve']);
        $item = $this->item('node/9001', 'Fontaine du Parc');

        $data = $this->getJson($client, '/map/coverage/search?q=fontaine');
        self::assertResponseIsSuccessful();
        self::assertSame('© OpenStreetMap contributors (ODbL)', $data['attribution']);

        $results = $data['results'];
        self::assertCount(2, $results);                       // node/9001 appears once, as curated
        self::assertSame('node/9001', $results[0]['ref']);    // curated ranks first
        self::assertTrue($results[0]['curated']);
        self::assertSame($item->getId(), $results[0]['itemId']);
        self::assertSame('Fontaine du Parc', $results[0]['n']);
        self::assertSame('node/9002', $results[1]['ref']);
        self::assertFalse($results[1]['curated']);
        self::assertArrayNotHasKey('itemId', $results[1]);
        self::assertSame([50.4, 5.8], $results[1]['ll']);
    }

    public function testSearchRanksBySimilarity(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['ref' => 'node/9101', 'name' => 'La Grande Fontaine des Pèlerins']);
        self::insertCoveragePoi($db, ['ref' => 'node/9102', 'name' => 'Fontaine']);

        $results = $this->getJson($client, '/map/coverage/search?q=Fontaine')['results'];
        self::assertCount(2, $results);
        self::assertSame('node/9102', $results[0]['ref']);    // similarity('Fontaine') = 1 beats the long name
    }

    public function testSearchServiceKindSurfaces(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, [
            'ref' => 'node/9201', 'letter' => 'D', 'kind' => 'pump', 'name' => 'Pompe du village',
            'tags' => ['amenity' => 'compressed_air'],
        ]);

        $results = $this->getJson($client, '/map/coverage/search?q=pompe')['results'];
        self::assertSame('D', $results[0]['letter']);
        self::assertSame('pump', $results[0]['kind']);
    }

    public function testSearchOverlongQueryIsCappedAndStillAnswers(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        $name = 'Fontaine '.str_repeat('a', 55);           // exactly 64 chars = the controller's query cap
        self::insertCoveragePoi($db, ['ref' => 'node/9901', 'name' => $name]);

        // 64 matching chars + junk beyond the cap: the tail must be truncated
        // away before ILIKE/similarity, so the row still matches.
        $data = $this->getJson($client, '/map/coverage/search?q='.urlencode($name.'zzzz'));
        self::assertResponseIsSuccessful();
        self::assertSame('node/9901', $data['results'][0]['ref']);
    }

    public function testSearchShortQueryAnswersEmpty(): void
    {
        $client = static::createClient();
        self::ensureCoverageSchema($this->db());

        $data = $this->getJson($client, '/map/coverage/search?q=f');
        self::assertResponseIsSuccessful();
        self::assertSame([], $data['results']);
    }

    public function testNearbyGroupsCapsCommunityAndCountsTotals(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        // Five community fountains, nearest-first by construction …
        foreach (range(1, 5) as $i) {
            self::insertCoveragePoi($db, ['ref' => 'node/95'.$i, 'name' => 'Water '.$i, 'lat' => 50.4 + 0.002 * $i, 'lng' => 5.8]);
        }
        // … one curated fountain (leads the group) whose coverage twin must
        // neither list nor count (osm-data-architecture.md §8 dedupe) …
        self::insertCoveragePoi($db, ['ref' => 'node/9560', 'name' => 'Fontaine officielle', 'lat' => 50.401, 'lng' => 5.8]);
        $item = $this->item('node/9560', 'Fontaine officielle', 50.401, 5.8);
        // … and one far out of range.
        self::insertCoveragePoi($db, ['ref' => 'node/9570', 'name' => 'Fontaine lointaine', 'lat' => 51.5, 'lng' => 5.8]);

        $data = $this->getJson($client, '/map/coverage/nearby?lat=50.4&lng=5.8&km=5');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $data['groups']);
        $group = $data['groups'][0];
        self::assertSame('C', $group['letter']);
        self::assertSame(6, $group['total']);                 // 1 curated + 5 community in range
        self::assertCount(4, $group['items']);                // curated + community cap of 3
        self::assertTrue($group['items'][0]['curated']);
        self::assertSame($item->getId(), $group['items'][0]['itemId']);
        self::assertSame(['Water 1', 'Water 2', 'Water 3'], array_column(\array_slice($group['items'], 1), 'n')); // nearest 3, by distance
    }

    public function testNearbyKmClampsAtTwentyFiveKm(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['ref' => 'node/9601', 'name' => 'Fontaine à 11 km', 'lat' => 50.5, 'lng' => 5.8]);
        self::insertCoveragePoi($db, ['ref' => 'node/9602', 'name' => 'Fontaine à 30 km', 'lat' => 50.67, 'lng' => 5.8]);

        $data = $this->getJson($client, '/map/coverage/nearby?lat=50.4&lng=5.8&km=999');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $data['groups']);
        self::assertSame(1, $data['groups'][0]['total']);  // the 30 km row is beyond the 25 km clamp
        self::assertSame('node/9601', $data['groups'][0]['items'][0]['ref']);
    }

    public function testNearbyKmDefaultsToFiveKm(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['ref' => 'node/9611', 'name' => 'Fontaine à 2 km', 'lat' => 50.418, 'lng' => 5.8]);
        self::insertCoveragePoi($db, ['ref' => 'node/9612', 'name' => 'Fontaine à 8 km', 'lat' => 50.472, 'lng' => 5.8]);

        $data = $this->getJson($client, '/map/coverage/nearby?lat=50.4&lng=5.8');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $data['groups']);
        self::assertSame(1, $data['groups'][0]['total']);  // the 8 km row is outside the 5 km default
        self::assertSame('node/9611', $data['groups'][0]['items'][0]['ref']);
    }

    public function testNearbyInvalidCoordsIs422(): void
    {
        $client = static::createClient();
        self::ensureCoverageSchema($this->db());

        $client->request('GET', '/map/coverage/nearby?lat=abc&lng=5.8');
        self::assertResponseStatusCodeSame(422);
        $client->request('GET', '/map/coverage/nearby?lat=95&lng=5.8');
        self::assertResponseStatusCodeSame(422);
    }

    public function testCountsPerLetter(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['ref' => 'node/9701', 'letter' => 'C']);
        self::insertCoveragePoi($db, ['ref' => 'node/9702', 'letter' => 'C']);
        self::insertCoveragePoi($db, ['ref' => 'way/9703', 'letter' => 'H', 'name' => 'Abri', 'tags' => ['amenity' => 'shelter']]);
        // A stray non-catalogue letter must never leak into the rail shape:
        // {C..J} is code-guaranteed (POI_LETTERS_SQL), not data-dependent.
        self::insertCoveragePoi($db, ['ref' => 'node/9704', 'letter' => 'X', 'name' => 'Stray']);

        $data = $this->getJson($client, '/map/coverage/counts');
        self::assertResponseIsSuccessful();
        self::assertSame(['C' => 2, 'H' => 1], $data['counts']);
        self::assertSame('© OpenStreetMap contributors (ODbL)', $data['attribution']);
        self::assertSame('max-age=3600, public', $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testSearchEtagRevalidates304(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['ref' => 'node/9801', 'name' => 'Fontaine etag']);

        $client->request('GET', '/map/coverage/search?q=fontaine');
        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertSame('max-age=300, public', $response->headers->get('Cache-Control'));
        $etag = (string) $response->headers->get('ETag');

        $client->request('GET', '/map/coverage/search?q=fontaine', [], [], ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);
    }

    public function testOverLimitIs429(): void
    {
        $client = static::createClient();
        self::ensureCoverageSchema($this->db());

        // Drain the limiter directly (RideCheckControllerTest convention: the
        // array cache pool resets on kernel reboot between HTTP requests, so
        // 121 real requests would never trip it — consume 120 via the factory,
        // then let the single HTTP request be the 121st).
        $factory = static::getContainer()->get('limiter.coverage_read');
        $limiter = $factory->create('ip-127.0.0.1');
        for ($i = 0; $i < 120; ++$i) {
            self::assertTrue($limiter->consume()->isAccepted());
        }

        $client->request('GET', '/map/coverage/counts');
        self::assertResponseStatusCodeSame(429);
    }
}
