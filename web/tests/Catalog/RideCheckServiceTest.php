<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\RideCheckService;
use App\Tests\Coverage\CoverageSchema;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Ride-check (spec 2026-07-14 §4.2): given an uploaded GPX, list the served
 * catalog items inside a corridor of the track (grouped by letter, ordered by
 * distance along the ride) plus the Commons routes the ride genuinely follows.
 * Real PostGIS via KernelTestCase — the point is the ST_* corridor maths.
 * Nothing may be persisted by the service itself.
 *
 * Geometry: a straight ~2.1 km west→east test track at lat 50.4000
 * (lng 5.8000 → 5.8300). At this latitude 0.001° lat ≈ 111 m, 0.001° lng ≈ 71 m.
 */
final class RideCheckServiceTest extends KernelTestCase
{
    use CoverageSchema;

    #[\Override]
    protected function setUp(): void
    {
        // Ride-check now reads coverage_poi on every check() (a pipeline-owned
        // table absent from Doctrine migrations), so the whole class needs it
        // present — the same in-transaction DDL the coverage read-path tests use.
        self::ensureCoverageSchema($this->db());
    }

    private function db(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    /** GPX 1.1 document from [lat, lng, ?ele] triples. */
    private static function gpx(array $pts): string
    {
        $trk = '';
        foreach ($pts as $p) {
            $ele = \array_key_exists(2, $p) && null !== $p[2] ? sprintf('<ele>%s</ele>', $p[2]) : '';
            $trk .= sprintf('<trkpt lat="%.6F" lon="%.6F">%s</trkpt>', $p[0], $p[1], $ele);
        }

        return '<?xml version="1.0" encoding="UTF-8"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1"><trk><trkseg>'.$trk.'</trkseg></trk></gpx>';
    }

    /** The standard test ride: 7 points, ~2.1 km, 30 m of climbing. */
    private static function ride(): string
    {
        $pts = [];
        foreach (range(0, 6) as $i) {
            $pts[] = [50.4000, 5.8000 + 0.005 * $i, 100 + 5 * $i];
        }

        return self::gpx($pts);
    }

    private static function point(float $lat, float $lng): string
    {
        return json_encode(['type' => 'Point', 'coordinates' => [$lng, $lat]], \JSON_THROW_ON_ERROR);
    }

    private static function line(array $coords): string
    {
        return json_encode(['type' => 'LineString', 'coordinates' => $coords], \JSON_THROW_ON_ERROR);
    }

    private function seedItem(string $letter, string $name, string $geomJson, string $ref, ItemState $state = ItemState::Unverified): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = (new Item())->setLetter($letter)->setName($name)
            ->setGeom($geomJson)->setCountryCode('BE')
            ->setState($state)->setSource(ItemSource::Osm)
            ->setSourceRef('node/'.$ref)
            ->setAttributes([]);
        $em->persist($item);
        $em->flush();

        return (int) $item->getId();
    }

    private function seedRoute(string $name, array $coords, string $ref): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = (new RecommendedRoute())->setName($name)
            ->setGeom(self::line($coords))->setDistanceM(1_000)->setAscentM(0)
            ->setState(ItemState::Verified)->setSource(ItemSource::Auto)
            ->setSourceRef('fx:'.$ref)
            ->setAttributes([]);
        $em->persist($route);
        $em->flush();

        return (int) $route->getId();
    }

    private function service(): RideCheckService
    {
        return static::getContainer()->get(RideCheckService::class);
    }

    public function testFindsItemsInsideCorridorGroupedAndOrdered(): void
    {
        // Two water points ~50 m north of the track, early and late along it.
        $early = $this->seedItem('C', 'Fontaine early', self::point(50.40045, 5.8050), 'rc-early');
        $late = $this->seedItem('C', 'Fontaine late', self::point(50.40045, 5.8250), 'rc-late');

        $result = $this->service()->check(self::ride(), 250);

        $letters = array_column($result['groups'], 'letter');
        self::assertContains('C', $letters);
        $group = $result['groups'][array_search('C', $letters, true)];
        $ids = array_column($group['items'], 'id');
        self::assertSame([$early, $late], $ids, 'items must be ordered by distance along the ride');
        self::assertFalse($group['truncated']);

        $first = $group['items'][0];
        self::assertSame('Fontaine early', $first['name']);
        self::assertEqualsWithDelta(50.40045, $first['ll'][0], 0.0001);
        self::assertEqualsWithDelta(5.8050, $first['ll'][1], 0.0001);
        self::assertEqualsWithDelta(50, $first['distM'], 15);
        self::assertGreaterThan(0.0, $first['alongKm']);
        self::assertLessThan($group['items'][1]['alongKm'], $first['alongKm']);
    }

    public function testExcludesSurfaceSegmentsRetiredItemsAndFarItems(): void
    {
        // A-segment lying exactly on the track: corridor noise, never listed.
        $this->seedItem('A', 'Asphalt on track', self::line([[5.8000, 50.4000], [5.8300, 50.4000]]), 'rc-a');
        // Retired stay directly on the track: not served, never listed.
        $this->seedItem('E', 'Closed hostel', self::point(50.4000, 5.8150), 'rc-retired', ItemState::Retired);
        // Water point ~5 km north: outside every allowed corridor.
        $this->seedItem('C', 'Far fountain', self::point(50.4450, 5.8150), 'rc-far');

        $result = $this->service()->check(self::ride(), 1000);

        $all = [];
        foreach ($result['groups'] as $group) {
            foreach ($group['items'] as $item) {
                $all[] = $item['name'];
            }
        }
        self::assertNotContains('Asphalt on track', $all);
        self::assertNotContains('Closed hostel', $all);
        self::assertNotContains('Far fountain', $all);
    }

    public function testRadiusChangesMembership(): void
    {
        // Stay ~400 m north of the track: inside at 500 m, outside at 250 m.
        $this->seedItem('E', 'Ferme du plateau', self::point(50.4036, 5.8150), 'rc-400m');

        $at500 = $this->service()->check(self::ride(), 500);
        $at250 = $this->service()->check(self::ride(), 250);

        $names = static function (array $result): array {
            $out = [];
            foreach ($result['groups'] as $group) {
                foreach ($group['items'] as $item) {
                    $out[] = $item['name'];
                }
            }

            return $out;
        };
        self::assertContains('Ferme du plateau', $names($at500));
        self::assertNotContains('Ferme du plateau', $names($at250));
        self::assertSame(500, $at500['radiusM']);
        self::assertSame(250, $at250['radiusM']);
    }

    public function testRouteOverlapThreshold(): void
    {
        // Parallel Commons route ~25 m north for ~1.07 km: genuinely followed.
        $followed = $this->seedRoute('RAVeL parallel', [[5.8000, 50.40022], [5.8150, 50.40022]], 'rc-par');
        // Perpendicular route crossing the track: overlap ≈ 2×radius only, not followed.
        $this->seedRoute('Crossing route', [[5.8100, 50.3900], [5.8100, 50.4100]], 'rc-x');

        $result = $this->service()->check(self::ride(), 250);

        $ids = array_column($result['routes'], 'id');
        self::assertSame([$followed], $ids);
        self::assertSame('RAVeL parallel', $result['routes'][0]['name']);
        self::assertEqualsWithDelta(1.1, $result['routes'][0]['sharedKm'], 0.2);
    }

    public function testRejectsShortRide(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ride_check.error.length_range');
        // ~140 m — a click, not a ride.
        $this->service()->check(self::gpx([[50.4000, 5.8000], [50.4000, 5.8020]]), 250);
    }

    public function testRejectsInvalidRadius(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ride_check.error.radius');
        $this->service()->check(self::ride(), 999);
    }

    public function testDistanceAscentAndTrackReported(): void
    {
        $result = $this->service()->check(self::ride(), 250);

        self::assertEqualsWithDelta(2.1, $result['distanceKm'], 0.2);
        self::assertSame(30, $result['ascentM']);
        self::assertIsArray($result['track']);
        self::assertGreaterThanOrEqual(2, \count($result['track']));
        // [lat, lng] order, matching every other map payload.
        self::assertEqualsWithDelta(50.4000, $result['track'][0][0], 0.0001);
        self::assertEqualsWithDelta(5.8000, $result['track'][0][1], 0.0001);
    }

    /** @return list<string> names in the coverage arm, flattened across letters */
    private static function coverageNames(array $result): array
    {
        $out = [];
        foreach ($result['coverage'] as $group) {
            foreach ($group['items'] as $item) {
                $out[] = $item['name'];
            }
        }

        return $out;
    }

    public function testFindsCoverageUtilityPointsInCorridorGroupedAndOrdered(): void
    {
        self::ensureCoverageSchema($this->db());
        // Two water points ~50 m north of the track, early and late along it,
        // plus one ~5 km north (outside every corridor).
        self::insertCoveragePoi($this->db(), ['letter' => 'C', 'name' => 'OSM fountain early', 'lat' => 50.40045, 'lng' => 5.8050, 'ref' => 'node/cov-early']);
        self::insertCoveragePoi($this->db(), ['letter' => 'D', 'name' => 'OSM bike pump late', 'lat' => 50.40045, 'lng' => 5.8250, 'ref' => 'node/cov-late']);
        self::insertCoveragePoi($this->db(), ['letter' => 'C', 'name' => 'OSM far fountain', 'lat' => 50.4450, 'lng' => 5.8150, 'ref' => 'node/cov-far']);

        $result = $this->service()->check(self::ride(), 250);

        self::assertArrayHasKey('coverage', $result);
        $names = self::coverageNames($result);
        self::assertContains('OSM fountain early', $names);
        self::assertContains('OSM bike pump late', $names);
        self::assertNotContains('OSM far fountain', $names, 'a point outside the corridor is not listed');

        $letters = array_column($result['coverage'], 'letter');
        $cGroup = $result['coverage'][array_search('C', $letters, true)];
        $first = $cGroup['items'][0];
        self::assertSame('OSM fountain early', $first['name']);
        self::assertEqualsWithDelta(50.40045, $first['ll'][0], 0.0001);
        self::assertGreaterThan(0.0, $first['alongKm']);
        self::assertFalse($cGroup['truncated']);
    }

    public function testCoverageItemsCarryTheirRef(): void
    {
        self::ensureCoverageSchema($this->db());
        self::insertCoveragePoi($this->db(), ['letter' => 'C', 'name' => 'OSM ref fountain', 'lat' => 50.40045, 'lng' => 5.8050, 'ref' => 'node/cov-ref']);

        $result = $this->service()->check(self::ride(), 250);

        $refs = [];
        foreach ($result['coverage'] as $group) {
            foreach ($group['items'] as $item) {
                $refs[$item['name']] = $item['ref'] ?? null;
            }
        }
        // `id` is a coverage_poi row id, which no endpoint accepts. Without the
        // ref the frontend cannot open the POI at all — /map/coverage/poi/{ref}
        // is the only lookup, so a coverage row is useless to a rider without it.
        self::assertSame('node/cov-ref', $refs['OSM ref fountain'] ?? null);
    }

    public function testCuratedItemsCarryNoRef(): void
    {
        self::ensureCoverageSchema($this->db());
        $this->seedItem('C', 'Fontaine curated', self::point(50.40045, 5.8050), 'rc-no-ref');

        $result = $this->service()->check(self::ride(), 250);

        $seen = 0;
        foreach ($result['groups'] as $group) {
            foreach ($group['items'] as $item) {
                ++$seen;
                self::assertArrayNotHasKey('ref', $item, 'the curated arm addresses items by id and must not grow a ref');
            }
        }
        self::assertGreaterThan(0, $seen, 'the fixture must actually put a curated item in the corridor');
    }

    public function testCoverageExcludesNonUtilityLetters(): void
    {
        self::ensureCoverageSchema($this->db());
        // E-stay, I-scenic, J-history coverage points right on the track: utility-only.
        self::insertCoveragePoi($this->db(), ['letter' => 'E', 'name' => 'OSM campsite', 'lat' => 50.4000, 'lng' => 5.8100, 'ref' => 'node/cov-e']);
        self::insertCoveragePoi($this->db(), ['letter' => 'I', 'name' => 'OSM viewpoint', 'lat' => 50.4000, 'lng' => 5.8150, 'ref' => 'node/cov-i']);
        self::insertCoveragePoi($this->db(), ['letter' => 'J', 'name' => 'OSM castle', 'lat' => 50.4000, 'lng' => 5.8200, 'ref' => 'node/cov-j']);

        $names = self::coverageNames($this->service()->check(self::ride(), 250));
        self::assertNotContains('OSM campsite', $names);
        self::assertNotContains('OSM viewpoint', $names);
        self::assertNotContains('OSM castle', $names);
    }

    public function testCoverageDedupsAgainstServedItemButKeepsNonServed(): void
    {
        self::ensureCoverageSchema($this->db());
        // 'node/dup': a served curated item exists for the same ref+letter → the
        // coverage POI is hidden (curated wins). 'node/keep': the only item for
        // that ref is retired (not served) → the coverage POI still shows.
        $this->seedItem('C', 'Curated fountain', self::point(50.40045, 5.8050), 'dup', ItemState::Unverified);
        $this->seedItem('C', 'Retired fountain', self::point(50.40045, 5.8250), 'keep', ItemState::Retired);
        self::insertCoveragePoi($this->db(), ['letter' => 'C', 'name' => 'OSM dup fountain', 'lat' => 50.40045, 'lng' => 5.8050, 'ref' => 'node/dup']);
        self::insertCoveragePoi($this->db(), ['letter' => 'C', 'name' => 'OSM keep fountain', 'lat' => 50.40045, 'lng' => 5.8250, 'ref' => 'node/keep']);

        $result = $this->service()->check(self::ride(), 250);
        $coverageNames = self::coverageNames($result);
        self::assertNotContains('OSM dup fountain', $coverageNames, 'a coverage POI matching a served item is deduped away');
        self::assertContains('OSM keep fountain', $coverageNames, 'a coverage POI whose only item is non-served still shows');

        // The served curated item is still in the curated groups (shown once, as curated).
        $curatedNames = [];
        foreach ($result['groups'] as $group) {
            foreach ($group['items'] as $item) {
                $curatedNames[] = $item['name'];
            }
        }
        self::assertContains('Curated fountain', $curatedNames);
    }

    /**
     * A region the track passes through, as a box around the test ride's
     * latitude. `admin_level` decides operationality: the deepest level a
     * country has is the one that scopes (catalog-data-model.md §2.4).
     */
    private function seedRegion(string $slug, string $name, string $cc, float $lngW, float $lngE, int $adminLevel = 4): int
    {
        $db = $this->db();
        $db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES (?, ?, ST_GeomFromText(?, 4326), 1000, ?, ?, ?, 'test', NOW(), NOW())",
            [$slug, $name, sprintf('POLYGON((%1$F 50.3,%1$F 50.5,%2$F 50.5,%2$F 50.3,%1$F 50.3))', $lngW, $lngE),
                $cc, strtoupper(substr($slug, 0, 5)), $adminLevel],
        );

        return (int) $db->lastInsertId('region_id_seq');
    }

    public function testTheRideNamesEveryRegionItCrosses(): void
    {
        // The ride runs west→east from 5.8000 to 5.8300; these two boxes split it.
        $west = $this->seedRegion('rc-west', 'West province', 'BE', 5.75, 5.815);
        $east = $this->seedRegion('rc-east', 'East province', 'BE', 5.815, 5.90);
        $this->seedRegion('rc-away', 'Somewhere else', 'BE', 6.50, 6.60);

        $regions = $this->service()->check(self::ride(), 250)['regions'];

        self::assertSame([$west, $east], array_column($regions, 'id'), 'both crossed regions, in ride order');
        self::assertSame(['West province', 'East province'], array_column($regions, 'name'));
        self::assertSame(['BE', 'BE'], array_column($regions, 'countryCode'));
    }

    public function testACountryOutlineIsNeverOfferedAsAScope(): void
    {
        // Same country, two levels: only the deepest one scopes. A level-2
        // outline covering the ride must not join the answer.
        $province = $this->seedRegion('rc-prov', 'Province', 'BE', 5.75, 5.90, 4);
        $this->seedRegion('rc-country', 'Whole country', 'BE', 5.00, 6.50, 2);

        $regions = $this->service()->check(self::ride(), 250)['regions'];

        self::assertSame([$province], array_column($regions, 'id'), 'the level-2 outline is not a scope chip');
    }

    public function testARideOutsideEveryRegionAnswersWithNone(): void
    {
        $this->seedRegion('rc-elsewhere', 'Elsewhere', 'BE', 6.50, 6.60);

        self::assertSame([], $this->service()->check(self::ride(), 250)['regions']);
    }
}
