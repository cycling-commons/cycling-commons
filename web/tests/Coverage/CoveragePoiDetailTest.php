<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
        $item = (new Item())->setLetter('B')->setName('Fontaine curated')
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

        // v1 extracts nodes + ways only (coverage-provider.md §3); the router
        // requirement enforces it — relations 404 (coverage-provider.md §5: osmType ∈ {node, way}).
        $client->request('GET', '/map/coverage/poi/relation/1');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDetailServesWhitelistedTagsOnly(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, [
            'ref' => 'node/61146471', 'letter' => 'B', 'name' => 'Fontaine Sainte-Anne',
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
        self::assertSame('B', $data['letter']);
        self::assertSame('Fontaine Sainte-Anne', $data['name']);
        self::assertNull($data['kind']);
        self::assertSame([50.4005, 5.8102], $data['ll']);
        // Store rich, serve trimmed (coverage-provider.md §5): only TAG_WHITELIST keys leave
        // the server. assertEquals — jsonb does not preserve key order.
        // `wikidata` joined the whitelist with the Commons photo cache
        // (coverage-provider.md §7): it is the citation for a picture a rider
        // may be looking at. `amenity` joined it on 2026-09-04: the water pin
        // is drawn blue for `drinking_water=yes` OR for an
        // `amenity=drinking_water` node with nothing said against it, and
        // without the tag the drawer could not tell the second case from
        // "nobody said anything", so the panel read "unknown" beside a blue
        // pin. `source` is still trimmed, which is the assertion that matters
        // here.
        self::assertEquals(
            ['amenity' => 'drinking_water', 'drinking_water' => 'yes', 'opening_hours' => '24/7',
                'operator' => 'Ville de Test', 'wikidata' => 'Q1234567'],
            $data['tags'],
        );
        self::assertArrayNotHasKey('source', (array) $data['tags'], 'the whitelist still trims');
        self::assertNull($data['curated']);
        self::assertSame('© OpenStreetMap contributors (ODbL)', $data['attribution']);
    }

    public function testWayRefResolves(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, [
            'ref' => 'way/123456', 'letter' => 'G', 'name' => 'Abri du bois',
            'tags' => ['amenity' => 'shelter'],
        ]);

        $data = $this->getJson($client, '/map/coverage/poi/way/123456');
        self::assertResponseIsSuccessful();
        self::assertSame('way/123456', $data['ref']);
        self::assertSame('G', $data['letter']);
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
        self::insertCoveragePoi($db, ['ref' => 'node/777001', 'letter' => 'B', 'name' => 'Fontaine OSM']);
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

    /**
     * The curated overlay of a scenic view carries only the photos whose
     * camera stood near the item's pin (PhotoValidator), the same filter the
     * catalog payload applies. A water tap's overlay keeps a far photo.
     */
    public function testScenicOverlayDropsPhotosTakenAwayFromThePin(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        // The item pin is 50.4, 5.8; 0.0009 degrees north is about 100 m, 0.0036 about 400 m.
        $near = ['sm' => 'https://img.test/near.webp', 'cameraAt' => [50.4009, 5.8], 'credit' => 'Jane Rider', 'license' => 'CC BY-SA 4.0'];
        $far = ['sm' => 'https://img.test/far.webp', 'cameraAt' => [50.4036, 5.8], 'credit' => 'Jane Rider', 'license' => 'CC BY-SA 4.0'];

        self::insertCoveragePoi($db, ['ref' => 'node/777010', 'letter' => 'P', 'name' => 'Belvédère OSM', 'tags' => ['tourism' => 'viewpoint']]);
        $scenic = $this->item('node/777010');
        $scenic->setLetter('P')->setAttributes(['photo' => $far, 'photos' => [$far, $near]]);
        self::insertCoveragePoi($db, ['ref' => 'node/777011', 'letter' => 'B', 'name' => 'Fontaine photo']);
        $tap = $this->item('node/777011');
        $tap->setAttributes(['potable' => 'yes', 'photo' => $far]);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $fields = $this->getJson($client, '/map/coverage/poi/node/777010')['curated']['fields'];
        self::assertArrayNotHasKey('photo', $fields, 'a photo from 400 m away is not the view from the pin');
        self::assertSame(['https://img.test/near.webp'], array_column($fields['photos'], 'sm'));

        $fields = $this->getJson($client, '/map/coverage/poi/node/777011')['curated']['fields'];
        self::assertSame('https://img.test/far.webp', $fields['photo']['sm'] ?? null, 'other letters are unaffected');
    }

    public function testUnservedItemDoesNotOverlay(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['ref' => 'node/777002', 'letter' => 'B', 'name' => 'Fontaine rejetée']);
        $this->item('node/777002', ItemState::Rejected);

        $data = $this->getJson($client, '/map/coverage/poi/node/777002');
        self::assertResponseIsSuccessful();
        self::assertNull($data['curated']);
    }

    public function testUntouchedLegacyRowDoesNotOverlay(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        // Coverage-retirement predicate (CoverageRetirement::untouchedOsmSql):
        // imported OSM, unverified, zero human touches — its tile renders as
        // community, so the drawer must not present a curated{...} overlay
        // (the app:coverage:retire-legacy --force run must not change what
        // the drawer shows: coverage-provider.md §9 "zero display change").
        self::insertCoveragePoi($db, ['ref' => 'node/777004', 'letter' => 'B', 'name' => 'Fontaine oubliée']);
        $this->item('node/777004', ItemState::Unverified);

        $data = $this->getJson($client, '/map/coverage/poi/node/777004');
        self::assertResponseIsSuccessful();
        self::assertNull($data['curated']);
    }

    public function testHumanTouchedUnverifiedRowStillOverlays(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        // Unverified but a rider confirmed it — "anything a human ever
        // touched stays canonical" (coverage-provider.md §9): it stays
        // payload-served, so the overlay must survive, state included.
        self::insertCoveragePoi($db, ['ref' => 'node/777005', 'letter' => 'B', 'name' => 'Fontaine confirmée']);
        $item = $this->item('node/777005', ItemState::Unverified);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new ItemConfirmation((int) $item->getId(), 9201, ConfirmationStance::Potable));
        $em->flush();

        $data = $this->getJson($client, '/map/coverage/poi/node/777005');
        self::assertResponseIsSuccessful();
        self::assertSame($item->getId(), $data['curated']['itemId']);
        self::assertSame('unverified', $data['curated']['state']);
        self::assertEquals(['potable' => 1], $data['curated']['confirmations']);
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
