<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Coverage;

use App\Catalog\ConfirmationStance;
use App\Catalog\Entity\Item;
use App\Catalog\Entity\ItemConfirmation;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /map/coverage/poi/{osmType}/{osmId}
 * (coverage-provider.md §5): drawer detail for a tile POI —
 * display-whitelisted cached OSM tags, curated overlay joined on source_ref
 * (osm-data-architecture.md §8), ODbL attribution, public HTTP caching.
 */
final class CoveragePoiDetailTest extends WebTestCase
{
    use CoverageSchema;

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    /** A canonical item sharing a coverage ref (the overlay source). */
    private function item(string $sourceRef, ItemState $state = ItemState::Verified): Item
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = (new Item())->setLetter('C')->setName('Fontaine curated')
            ->setGeom('{"type":"Point","coordinates":[5.8,50.4]}')->setCountryCode('BE')
            ->setState($state)->setSource(ItemSource::Osm)->setSourceRef($sourceRef)
            ->setAttributes(['potable' => 'yes']);
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

    public function testUnknownRefIs404(): void
    {
        $client = static::createClient();
        self::ensureCoverageSchema($this->db());

        $client->request('GET', '/map/coverage/poi/node/999999999');
        self::assertResponseStatusCodeSame(404);
    }

    public function testRelationTypeIsNotRouted(): void
    {
        $client = static::createClient();
        self::ensureCoverageSchema($this->db());

        // B1 (design §2): v1 extracts nodes + ways only — relations 404 at the router.
        $client->request('GET', '/map/coverage/poi/relation/1');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDetailServesWhitelistedTagsOnly(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, [
            'ref' => 'node/61146471', 'letter' => 'C', 'name' => 'Fontaine Sainte-Anne',
            'lat' => 50.4005, 'lng' => 5.8102,
            'tags' => [
                'amenity' => 'drinking_water', 'drinking_water' => 'yes',
                'opening_hours' => '24/7', 'operator' => 'Ville de Test',
                'wikidata' => 'Q1234567', 'source' => 'survey 2024',
            ],
        ]);

        $data = $this->getJson($client, '/map/coverage/poi/node/61146471');
        self::assertResponseIsSuccessful();
        self::assertSame('node/61146471', $data['ref']);
        self::assertSame('C', $data['letter']);
        self::assertSame('Fontaine Sainte-Anne', $data['name']);
        self::assertNull($data['kind']);
        self::assertSame([50.4005, 5.8102], $data['ll']);
        // Store rich, serve trimmed (design §4): only TAG_WHITELIST keys leave
        // the server. assertEquals — jsonb does not preserve key order.
        self::assertEquals(
            ['drinking_water' => 'yes', 'opening_hours' => '24/7', 'operator' => 'Ville de Test'],
            $data['tags'],
        );
        self::assertNull($data['curated']);
        self::assertSame('© OpenStreetMap contributors (ODbL)', $data['attribution']);
    }

    public function testWayRefResolves(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, [
            'ref' => 'way/123456', 'letter' => 'H', 'name' => 'Abri du bois',
            'tags' => ['amenity' => 'shelter'],
        ]);

        $data = $this->getJson($client, '/map/coverage/poi/way/123456');
        self::assertResponseIsSuccessful();
        self::assertSame('way/123456', $data['ref']);
        self::assertSame('H', $data['letter']);
    }

    public function testServiceKindSurfaces(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, [
            'ref' => 'node/8800', 'letter' => 'D', 'kind' => 'pump', 'name' => 'Pompe publique',
            'tags' => ['amenity' => 'compressed_air'],
        ]);

        $data = $this->getJson($client, '/map/coverage/poi/node/8800');
        self::assertResponseIsSuccessful();
        self::assertSame('pump', $data['kind']);
    }

    public function testCuratedOverlayMergesItemFieldsAndConfirmations(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['ref' => 'node/777001', 'letter' => 'C', 'name' => 'Fontaine OSM']);
        $item = $this->item('node/777001');

        // Two riders confirmed potable, one disagreed (plain int user ids —
        // item_confirmation has no FK, matching ItemConfirmationService's raw tallies).
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new ItemConfirmation((int) $item->getId(), 9001, ConfirmationStance::Potable));
        $em->persist(new ItemConfirmation((int) $item->getId(), 9002, ConfirmationStance::Potable));
        $em->persist(new ItemConfirmation((int) $item->getId(), 9003, ConfirmationStance::NotPotable));
        $em->flush();

        $data = $this->getJson($client, '/map/coverage/poi/node/777001');
        self::assertResponseIsSuccessful();
        $curated = $data['curated'];
        self::assertSame($item->getId(), $curated['itemId']);
        self::assertSame('verified', $curated['state']);
        self::assertSame('yes', $curated['fields']['potable']);
        self::assertSame('Fontaine curated', $curated['fields']['name']);   // Item::NAME_FIELD merged in
        self::assertEquals(['potable' => 2, 'not_potable' => 1], $curated['confirmations']);
    }

    public function testUnservedItemDoesNotOverlay(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['ref' => 'node/777002', 'letter' => 'C', 'name' => 'Fontaine rejetée']);
        $this->item('node/777002', ItemState::Rejected);

        $data = $this->getJson($client, '/map/coverage/poi/node/777002');
        self::assertResponseIsSuccessful();
        self::assertNull($data['curated']);
    }

    public function testEtagRevalidationAnd304(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['ref' => 'node/777003']);

        $client->request('GET', '/map/coverage/poi/node/777003');
        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertSame('max-age=300, public', $response->headers->get('Cache-Control'));
        $etag = (string) $response->headers->get('ETag');
        self::assertNotSame('', $etag);

        $client->request('GET', '/map/coverage/poi/node/777003', [], [], ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);
    }
}
