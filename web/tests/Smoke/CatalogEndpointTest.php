<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CatalogEndpointTest extends WebTestCase
{
    /**
     * The map is built from region slices and nothing else
     * (catalog-data-model.md §9.1): every region the stamps name, region 0
     * included for the places no region holds, together serve every row.
     */
    public function testTheRegionSlicesTogetherServeEveryLetter(): void
    {
        $client = static::createClient();

        $this->seed();

        $client->request('GET', '/map/catalog/stamps.json');
        /** @var array<string, string> $stamps */
        $stamps = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $refs = [];
        $d = 0;
        $r = 0;
        foreach (array_keys($stamps) as $rid) {
            $client->request('GET', '/map/catalog/region/'.$rid.'.json');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'application/json');
            /** @var array<string, mixed> $data */
            $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            // No heat key: the heat points are a derived layer without a
            // catalogue letter, served from /map/heat.json so they stop riding
            // the critical payload for an Off-by-default layer.
            foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'N', 'O', 'P', 'Q', 'R', 'refs'] as $key) {
                self::assertArrayHasKey($key, $data);
            }
            self::assertArrayNotHasKey('heat', $data, 'the heat points are not on the critical payload');
            $refs = array_merge($refs, $data['refs']);
            $d += \count($data['D']['features']);
            $r += \count($data['R']);
        }
        self::assertContains('node/1001', $refs, 'curated-OSM refs for tile dedupe');
        self::assertSame(3, $d);
        self::assertSame(1, $r);

        // Nothing serves the whole world in one document any more.
        $client->request('GET', '/map/catalog.json');
        self::assertResponseStatusCodeSame(404);

        // …and the heat points, on their own endpoint, with the same
        // public-cacheable + ETag discipline.
        $client->request('GET', '/map/heat.json');
        $heatResponse = $client->getResponse();
        self::assertResponseIsSuccessful();
        /** @var list<array{0: float, 1: float, 2: ?string, 3: ?int}> $heat */
        $heat = json_decode((string) $heatResponse->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(2, $heat);
        self::assertTrue($heatResponse->headers->getCacheControlDirective('public'));

        $client->request('GET', '/map/heat.json', [], [], ['HTTP_IF_NONE_MATCH' => $heatResponse->getEtag()]);
        self::assertResponseStatusCodeSame(304);
    }

    /**
     * The two endpoints that make a curator's approval reach the riders of one
     * region without touching anybody else's cache
     * (catalog-data-model.md §9.1): the stamps a browser checks on every boot,
     * and the region slice it splices over the copy it holds.
     */
    public function testRegionFreshnessServesStampsAndASlicePerRegion(): void
    {
        $client = static::createClient();
        $this->seed();

        $client->request('GET', '/map/catalog/stamps.json');
        self::assertResponseIsSuccessful();
        $stampsResponse = $client->getResponse();
        // Always revalidated: this is the one thing that has to be current on a
        // plain reload, and it is kilobytes, so a 304 is the usual answer.
        self::assertTrue($stampsResponse->headers->getCacheControlDirective('public'));
        self::assertTrue($stampsResponse->headers->getCacheControlDirective('no-cache'));
        self::assertNotEmpty($stampsResponse->getEtag());
        /** @var array<string, string> $stamps */
        $stamps = json_decode((string) $stampsResponse->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertNotEmpty($stamps);
        foreach ($stamps as $rid => $stamp) {
            self::assertMatchesRegularExpression('/^\d+$/', (string) $rid, 'keyed by region id, with 0 for the region-less rows');
            self::assertMatchesRegularExpression('/^[0-9a-f]{8,}$/', $stamp);
        }

        $client->request('GET', '/map/catalog/stamps.json', [], [], ['HTTP_IF_NONE_MATCH' => $stampsResponse->getEtag()]);
        self::assertResponseStatusCodeSame(304);

        $rid = (int) array_key_first(array_diff_key($stamps, ['0' => null]));
        $client->request('GET', '/map/catalog/region/'.$rid.'.json');
        self::assertResponseIsSuccessful();
        $sliceResponse = $client->getResponse();
        // The URL carries the stamp, and the stamp carries the day, so a day is
        // safe: a decision or a new day mints a new one.
        self::assertStringContainsString('max-age=86400', (string) $sliceResponse->headers->get('Cache-Control'));
        self::assertStringContainsString('public', (string) $sliceResponse->headers->get('Cache-Control'));
        self::assertNotEmpty($sliceResponse->getEtag());

        /** @var array<string, mixed> $slice */
        $slice = json_decode((string) $sliceResponse->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame($rid, $slice['rid']);
        self::assertSame($stamps[(string) $rid], $slice['stamp']);
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'N', 'O', 'P', 'Q', 'R', 'refs', 'providers'] as $key) {
            self::assertArrayHasKey($key, $slice, 'the slice speaks the payload\'s shapes, so the map needs no second code path');
        }
        foreach ($slice['D']['features'] as $feature) {
            self::assertSame($rid, $feature['properties']['rid'], 'a slice carries one region and no other');
        }

        $client->request('GET', '/map/catalog/region/'.$rid.'.json', [], [], ['HTTP_IF_NONE_MATCH' => $sliceResponse->getEtag()]);
        self::assertResponseStatusCodeSame(304);
    }

    /**
     * The map loads only the rider's own regions (catalog-data-model.md §9.1),
     * so a link to a place elsewhere tells the loader which region to bring
     * along: by id, or every region that holds the name. Region 0 is the
     * places no region holds; a link to nothing on the map needs nothing.
     */
    public function testALinkBringsItsRegionIntoTheFirstPaint(): void
    {
        $client = static::createClient();
        $this->seed();
        $db = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->getConnection();
        /** @var array{id: int|string, name: string, region_id: int|string} $row */
        $row = $db->fetchAssociative("SELECT id, name, region_id FROM item WHERE region_id IS NOT NULL AND letter = 'D' ORDER BY id LIMIT 1");
        $rid = (int) $row['region_id'];

        $boot = static function (string $query) use ($client): array {
            $client->request('GET', '/map'.$query);
            self::assertResponseIsSuccessful();
            self::assertSame(1, preg_match('~window\.CC_CATALOG_BOOT = (\{[^;]*\});~', (string) $client->getResponse()->getContent(), $m));

            return json_decode($m[1], true, 512, \JSON_THROW_ON_ERROR);
        };

        // share-links.js writes `?item=<id>/<slug>`; the id decides.
        self::assertSame(['regions' => [$rid]], $boot('?item='.$row['id'].'/some-name'));
        self::assertSame(['regions' => [$rid]], $boot('?feature='.rawurlencode(mb_strtoupper((string) $row['name']))), 'a name, case aside');
        self::assertSame(['regions' => []], $boot('?feature=Nowhere%20by%20this%20name'));
        self::assertSame(['regions' => []], $boot('?item=999999999'), 'nothing to load for a place that is gone');

        $db->executeStatement('UPDATE item SET region_id = NULL WHERE id = :id', ['id' => $row['id']]);
        self::assertSame(['regions' => [0]], $boot('?item='.$row['id']), 'region 0 holds the places no region holds');
    }

    /** The fixture catalog, on the same kernel (DAMA rolls it back). */
    private function seed(): void
    {
        $src = __DIR__.'/../fixtures/catalog';
        $dir = sys_get_temp_dir().'/catalog-endpoint-'.getmypid();
        @mkdir($dir, 0777, true);
        foreach (['region-square.geojson', 'services.json', 'routes.json', 'heat.json'] as $f) {
            copy($src.'/'.$f, $dir.'/'.$f);
        }
        $tester = new CommandTester((new Application(self::$kernel))->find('app:catalog:import'));
        $tester->execute(['dir' => $dir]);
        $tester->assertCommandIsSuccessful();
        // Coverage retirement (coverage-provider.md §9): untouched
        // osm/unverified POIs do not serve, and this asserts payload shape.
        static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->getConnection()->executeStatement(
            "UPDATE item SET state = 'verified' WHERE letter IN ('B','D','F','G','O','P','Q')",
        );
    }
}
