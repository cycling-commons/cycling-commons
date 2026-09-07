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
    private function item(string $sourceRef, string $name, float $lat = 50.4, float $lng = 5.8, string $letter = 'B'): Item
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

    /**
     * An imported-OSM row nobody has touched (coverage-retirement predicate,
     * CoverageRetirement::untouchedOsmSql): source=osm, state=unverified,
     * zero change_history/item_confirmation/submission. This is exactly what
     * app:coverage:retire-legacy would delete, so the query plane must key
     * it as community, not curated, to mirror the payload/tile pair.
     */
    private function untouchedOsmItem(string $sourceRef, string $name, float $lat = 50.4, float $lng = 5.8, string $letter = 'B'): Item
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = (new Item())->setLetter($letter)->setName($name)
            ->setGeom(json_encode(['type' => 'Point', 'coordinates' => [$lng, $lat]], \JSON_THROW_ON_ERROR))
            ->setCountryCode('BE')->setState(ItemState::Unverified)->setSource(ItemSource::Osm)
            ->setSourceRef($sourceRef)->setAttributes([]);
        $em->persist($item);
        $em->flush();

        return $item;
    }

    /** A served row a rider reported gone: dropped from the payload, twin still claimed (catalog-data-model.md §7). */
    private function goneItem(string $sourceRef, string $name, float $lat = 50.4, float $lng = 5.8, string $letter = 'B'): Item
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = $this->item($sourceRef, $name, $lat, $lng, $letter)->setAttributes(['condition' => 'Not there anymore']);
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

    public function testSearchUntouchedLegacyRowListsAsCommunityNotShadowed(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        // An untouched legacy row (coverage-retirement predicate) must NOT
        // be treated as payload-served: it lists once, as community — its
        // coverage twin is not shadowed (finding 1, coverage-provider.md §9
        // "zero display change").
        self::insertCoveragePoi($db, ['ref' => 'node/9010', 'name' => 'Fontaine oubliée']);
        $this->untouchedOsmItem('node/9010', 'Fontaine oubliée');

        $results = $this->getJson($client, '/map/coverage/search?q=fontaine')['results'];
        self::assertCount(1, $results);
        self::assertSame('node/9010', $results[0]['ref']);
        self::assertFalse($results[0]['curated']);
        self::assertArrayNotHasKey('itemId', $results[0]);
    }

    public function testSearchHumanTouchedUnverifiedRowStaysCuratedAndShadowsTwin(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        // Unverified but a rider confirmed it — "anything a human ever
        // touched stays canonical" (coverage-provider.md §9): it must stay
        // curated and its coverage twin must stay shadowed.
        self::insertCoveragePoi($db, ['ref' => 'node/9011', 'name' => 'Fontaine confirmée']);
        $item = $this->untouchedOsmItem('node/9011', 'Fontaine confirmée');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new ItemConfirmation((int) $item->getId(), 9101, ConfirmationStance::Exists));
        $em->flush();

        $results = $this->getJson($client, '/map/coverage/search?q=fontaine')['results'];
        self::assertCount(1, $results);
        self::assertSame('node/9011', $results[0]['ref']);
        self::assertTrue($results[0]['curated']);
        self::assertSame($item->getId(), $results[0]['itemId']);
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
        self::assertSame('B', $group['letter']);
        self::assertSame(6, $group['total']);                 // 1 curated + 5 community in range
        self::assertCount(4, $group['items']);                // curated + community cap of 3
        self::assertTrue($group['items'][0]['curated']);
        self::assertSame($item->getId(), $group['items'][0]['itemId']);
        self::assertSame(['Water 1', 'Water 2', 'Water 3'], array_column(\array_slice($group['items'], 1), 'n')); // nearest 3, by distance
    }

    public function testNearbyUntouchedLegacyRowListsAsCommunityNotShadowed(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['ref' => 'node/9561', 'name' => 'Fontaine oubliée', 'lat' => 50.401, 'lng' => 5.8]);
        $this->untouchedOsmItem('node/9561', 'Fontaine oubliée', 50.401, 5.8);

        $data = $this->getJson($client, '/map/coverage/nearby?lat=50.4&lng=5.8&km=5');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $data['groups']);
        $group = $data['groups'][0];
        self::assertSame(1, $group['total']);
        self::assertCount(1, $group['items']);
        self::assertSame('node/9561', $group['items'][0]['ref']);
        self::assertFalse($group['items'][0]['curated']);
        self::assertArrayNotHasKey('itemId', $group['items'][0]);
    }

    /**
     * A row reported "Not there anymore" is gone from the map payload
     * (catalog-data-model.md §7). The query plane must agree: it lists in
     * neither tier, and its coverage twin stays hidden too, or the nearby
     * list names a pin the map does not draw.
     */
    public function testSearchGoneRowListsNowhereAndStillShadowsTwin(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['ref' => 'node/9012', 'name' => 'Fontaine disparue']);
        $this->goneItem('node/9012', 'Fontaine disparue');

        $results = $this->getJson($client, '/map/coverage/search?q=fontaine')['results'];
        self::assertSame([], $results);
    }

    public function testNearbyGoneRowListsNowhereAndStillShadowsTwin(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['ref' => 'node/9562', 'name' => 'Fontaine disparue', 'lat' => 50.401, 'lng' => 5.8]);
        $this->goneItem('node/9562', 'Fontaine disparue', 50.401, 5.8);

        $data = $this->getJson($client, '/map/coverage/nearby?lat=50.4&lng=5.8&km=5');
        self::assertResponseIsSuccessful();
        self::assertSame([], $data['groups']);
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
        self::insertCoveragePoi($db, ['ref' => 'node/9701', 'letter' => 'B']);
        self::insertCoveragePoi($db, ['ref' => 'node/9702', 'letter' => 'B']);
        self::insertCoveragePoi($db, ['ref' => 'way/9703', 'letter' => 'G', 'name' => 'Abri', 'tags' => ['amenity' => 'shelter']]);
        // A stray non-catalogue letter must never leak into the rail shape:
        // the coverage letter set is code-guaranteed (POI_LETTERS_SQL), not data-dependent.
        self::insertCoveragePoi($db, ['ref' => 'node/9704', 'letter' => 'X', 'name' => 'Stray']);

        $data = $this->getJson($client, '/map/coverage/counts');
        self::assertResponseIsSuccessful();
        self::assertSame(['B' => 2, 'G' => 1], $data['counts']);
        self::assertSame('© OpenStreetMap contributors (ODbL)', $data['attribution']);
        self::assertSame('max-age=3600, public', $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testCountsExcludePayloadServedTwinButIncludeUntouchedLegacyRow(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        // A confirmed (payload-served) item's coverage twin must not count
        // (minor finding 7: rail coherence with the payload-on-top map).
        self::insertCoveragePoi($db, ['ref' => 'node/9705', 'letter' => 'B', 'name' => 'Fontaine confirmée']);
        $this->item('node/9705', 'Fontaine confirmée');
        // An untouched legacy row is not payload-served — it must still count.
        self::insertCoveragePoi($db, ['ref' => 'node/9706', 'letter' => 'B', 'name' => 'Fontaine oubliée']);
        $this->untouchedOsmItem('node/9706', 'Fontaine oubliée');

        $data = $this->getJson($client, '/map/coverage/counts');
        self::assertResponseIsSuccessful();
        self::assertSame(['B' => 1], $data['counts']);
    }

    public function testSearchScopesToRidsAndCc(): void
    {
        // Region scope (map-and-search.md §4.5 Phase 3): rids filters
        // community + curated rows to the region; cc catches unsplit rows.
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['ref' => 'node/9901', 'name' => 'Fontaine Wallonne', 'region_id' => 1]);
        self::insertCoveragePoi($db, ['ref' => 'node/9902', 'name' => 'Fontaine Flamande', 'region_id' => 2]);
        self::insertCoveragePoi($db, ['ref' => 'node/9903', 'name' => 'Fontaine Néerlandaise', 'region_id' => null, 'country_code' => 'NL']);

        // rids=1 → only the region-1 row.
        $r = $this->getJson($client, '/map/coverage/search?q=fontaine&rids=1')['results'];
        self::assertSame(['node/9901'], array_column($r, 'ref'));

        // rids=1,2 (a country's stamped regions) → both Belgian rows, never NL.
        $r = $this->getJson($client, '/map/coverage/search?q=fontaine&rids=1,2')['results'];
        self::assertEqualsCanonicalizing(['node/9901', 'node/9902'], array_column($r, 'ref'));

        // cc=BE → the two BE-stamped rows; the NL row (cc='NL') is excluded.
        $r = $this->getJson($client, '/map/coverage/search?q=fontaine&cc=BE')['results'];
        self::assertEqualsCanonicalizing(['node/9901', 'node/9902'], array_column($r, 'ref'));

        // No params → every row (backward compatible).
        $r = $this->getJson($client, '/map/coverage/search?q=fontaine')['results'];
        self::assertCount(3, $r);
    }

    public function testSearchScopeOrsRegionAndCountryForUnsplitRows(): void
    {
        // A country scope sends rids (its stamped regions) AND cc; the OR arm
        // must admit an unsplit BE row (region_id NULL, cc='BE') the id list
        // can't match (map-and-search.md §4.5).
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['ref' => 'node/9911', 'name' => 'Fontaine stamped', 'region_id' => 1]);
        self::insertCoveragePoi($db, ['ref' => 'node/9912', 'name' => 'Fontaine unsplit', 'region_id' => null, 'country_code' => 'BE']);

        $r = $this->getJson($client, '/map/coverage/search?q=fontaine&rids=1&cc=BE')['results'];
        self::assertEqualsCanonicalizing(['node/9911', 'node/9912'], array_column($r, 'ref'));
    }

    public function testCuratedSearchArmScopesToRids(): void
    {
        // The curated (item) arm scopes by rid too, so a served POI outside the
        // scope never appears in the sidebar while the scope-filtered tiles hide it.
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['ref' => 'node/9921', 'name' => 'Fontaine curated in', 'region_id' => 1]);
        self::insertCoveragePoi($db, ['ref' => 'node/9922', 'name' => 'Fontaine curated out', 'region_id' => 2]);
        $this->item('node/9921', 'Fontaine curated in')->setRegionId(1);
        $this->item('node/9922', 'Fontaine curated out')->setRegionId(2);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $r = $this->getJson($client, '/map/coverage/search?q=fontaine&rids=1')['results'];
        self::assertSame(['node/9921'], array_column($r, 'ref'));
        self::assertTrue($r[0]['curated']);
    }

    public function testCuratedArmIsRidOnlyForMapParity(): void
    {
        // finding 6: the curated arm is rid-ONLY (drops cc), mirroring the map's
        // rid-only served-data gate — else the sidebar would list a curated POI
        // (region_id NULL, cc='BE') whose pin the map hides. The COMMUNITY arm
        // keeps cc, so an identically-shaped coverage row IS still listed.
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        // A community coverage row: region_id NULL, cc='BE' (kept by the cc arm).
        self::insertCoveragePoi($db, ['ref' => 'node/9961', 'name' => 'Fontaine community be', 'region_id' => null, 'country_code' => 'BE']);
        // A curated item with the same shape: region_id NULL, cc='BE'.
        self::insertCoveragePoi($db, ['ref' => 'node/9962', 'name' => 'Fontaine curated be', 'region_id' => null, 'country_code' => 'BE']);
        $this->item('node/9962', 'Fontaine curated be');   // item() leaves region_id NULL, cc='BE'
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        // Country scope (region ids + cc). The curated cc-only item is HIDDEN
        // (rid-only arm), the community cc-only row is SHOWN (cc arm).
        $r = $this->getJson($client, '/map/coverage/search?q=fontaine&rids=1,2&cc=BE')['results'];
        $refs = array_column($r, 'ref');
        self::assertContains('node/9961', $refs, 'community cc-only row stays listed (cc arm)');
        self::assertNotContains('node/9962', $refs, 'curated cc-only item is hidden — matches the rid-only map gate');
    }

    public function testCcRejectsTrailingNewline(): void
    {
        // finding 7: PCRE $ matches before a trailing \n, so 'BE\n' used to pass
        // and yield country_code = 'BE\n' matching nothing (the inverse of the
        // garbage-→-Everywhere contract). /D closes it: 'BE%0A' is garbage → cc
        // ignored → Everywhere, identical to the no-param counts.
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['ref' => 'node/9971', 'letter' => 'B', 'region_id' => 1, 'country_code' => 'BE']);

        $everywhere = $this->getJson($client, '/map/coverage/counts')['counts'];
        $newline = $this->getJson($client, '/map/coverage/counts?cc=BE%0A')['counts'];
        self::assertSame($everywhere, $newline, "'BE\\n' must be garbage → Everywhere, not an empty country_code='BE\\n' scope");
        self::assertSame(['B' => 1], $newline);
    }

    public function testRidsParserEdges(): void
    {
        // finding 17: zero-padded rids collapse onto their numeric value (no
        // wasted cap slot), overflow strings are rejected (garbage → Everywhere,
        // never a phantom empty scope), and an array-valued rids[] degrades to
        // Everywhere instead of Symfony's HTML 400.
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['ref' => 'node/9981', 'letter' => 'B', 'region_id' => 1]);
        self::insertCoveragePoi($db, ['ref' => 'node/9982', 'letter' => 'B', 'region_id' => 2]);

        // '01' is region 1 (zero-padded), deduped with '1' — scopes to region 1.
        self::assertSame(['B' => 1], $this->getJson($client, '/map/coverage/counts?rids=01,1')['counts']);

        // A 20-digit overflow saturates (int); rejected → Everywhere (both rows).
        self::assertSame(['B' => 2], $this->getJson($client, '/map/coverage/counts?rids=99999999999999999999')['counts']);

        // Array-valued param must not 500/400 — degrades to Everywhere.
        $client->request('GET', '/map/coverage/counts?rids[]=1');
        self::assertResponseIsSuccessful();
        self::assertSame(['B' => 2], json_decode((string) $client->getResponse()->getContent(), true)['counts']);
    }

    public function testCountsScopeToRids(): void
    {
        // Rail totals become scope-aware (map-and-search.md §4.5 Phase 3),
        // so "total" matches the scope-filtered "shown" dots the client renders.
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['ref' => 'node/9931', 'letter' => 'B', 'region_id' => 1]);
        self::insertCoveragePoi($db, ['ref' => 'node/9932', 'letter' => 'B', 'region_id' => 2]);
        self::insertCoveragePoi($db, ['ref' => 'way/9933', 'letter' => 'G', 'name' => 'Abri', 'tags' => ['amenity' => 'shelter'], 'region_id' => 1]);

        self::assertSame(['B' => 1, 'G' => 1], $this->getJson($client, '/map/coverage/counts?rids=1')['counts']);
        self::assertSame(['B' => 2, 'G' => 1], $this->getJson($client, '/map/coverage/counts')['counts']);
    }

    public function testNearbyScopesToRids(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        // Two community rows within 5 km of the same point, different regions.
        self::insertCoveragePoi($db, ['ref' => 'node/9941', 'name' => 'Fontaine A', 'lat' => 50.40, 'lng' => 5.80, 'region_id' => 1]);
        self::insertCoveragePoi($db, ['ref' => 'node/9942', 'name' => 'Fontaine B', 'lat' => 50.41, 'lng' => 5.81, 'region_id' => 2]);

        $groups = $this->getJson($client, '/map/coverage/nearby?lat=50.40&lng=5.80&km=5&rids=1')['groups'];
        $refs = [];
        foreach ($groups as $g) {
            foreach ($g['items'] as $it) {
                $refs[] = $it['ref'];
            }
        }
        self::assertSame(['node/9941'], $refs);
    }

    public function testScopeParamsAreCappedAndSanitised(): void
    {
        // Garbage rids are dropped; the id set is capped (map-and-search.md §4.5
        // §8 risk 10). A too-long list still answers (never a 500), scoped to
        // whatever survived the cap — here region 1 is within the first 24.
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['ref' => 'node/9951', 'name' => 'Fontaine capped', 'region_id' => 1]);

        $manyIds = implode(',', range(1, 60));
        $r = $this->getJson($client, '/map/coverage/search?q=fontaine&rids='.$manyIds.'&cc=zz9');
        self::assertResponseIsSuccessful();               // cc 'zz9' is not 2 alpha → ignored, no 500
        self::assertSame(['node/9951'], array_column($r['results'], 'ref'));
    }

    public function testCapTruncationIsRescuedByCc(): void
    {
        // finding 8/21: the 24-region cap is only safe because the cc arm is the
        // complete fallback. A row whose region sorts PAST the cap is dropped from
        // the rids IN-list, but a country scope's cc arm still counts it — proven
        // by comparing a >24-id scope WITH vs WITHOUT cc.
        $client = static::createClient();
        $db = $this->db();
        self::ensureCoverageSchema($db);
        // region_id 100 is beyond the 24-id cap when rids=1..30 (sorted, sliced).
        self::insertCoveragePoi($db, ['ref' => 'node/9991', 'letter' => 'B', 'region_id' => 100, 'country_code' => 'BE']);

        $ids = implode(',', range(1, 30));
        // With cc: the cc arm rescues the capped-out region-100 row.
        self::assertSame(['B' => 1], $this->getJson($client, '/map/coverage/counts?rids='.$ids.'&cc=BE')['counts']);
        // Without cc: region 100 is past the cap and there is no fallback → empty.
        self::assertSame([], $this->getJson($client, '/map/coverage/counts?rids='.$ids)['counts']);
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
        // Anonymous plane — the client must be told when to come back
        // (finding: 429 Retry-After) rather than hammering it immediately.
        $retryAfter = $client->getResponse()->headers->get('Retry-After');
        self::assertNotNull($retryAfter);
        self::assertGreaterThanOrEqual(0, (int) $retryAfter);
        self::assertLessThanOrEqual(60, (int) $retryAfter);   // sliding window is 1 minute
    }
}
