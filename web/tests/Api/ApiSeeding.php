<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Api;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Rows for the /v1 tests, written straight into the test database so each
 * test says exactly what exists. The fixture import gives the `test-square`
 * region (BE, 4-5 E, 50-51 N) and its rows, as PublicApiV1Test uses them.
 */
trait ApiSeeding
{
    private function db(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    /** The fixture catalogue: the test-square region, three D items, the "Test loop" route. */
    private function seedCatalog(KernelBrowser $client): void
    {
        $src = __DIR__.'/../fixtures/catalog';
        $dir = sys_get_temp_dir().'/public-api-v1-'.getmypid();
        @mkdir($dir, 0777, true);
        foreach (['region-square.geojson', 'services.json', 'routes.json', 'heat.json'] as $f) {
            copy($src.'/'.$f, $dir.'/'.$f);
        }
        $tester = new CommandTester((new Application($client->getKernel()))->find('app:catalog:import'));
        $tester->execute(['dir' => $dir]);
        $tester->assertCommandIsSuccessful();
        // Untouched osm rows are retired from serving; promote the fixtures.
        $this->db()->executeStatement("UPDATE item SET state = 'verified' WHERE letter IN ('B','D','F','G','O','P','Q')");
    }

    /** @param array<string, mixed> $attributes */
    private function item(string $letter, string $name, float $lon, float $lat, string $state = 'verified', array $attributes = [], string $source = 'manual', ?string $osmRef = null): int
    {
        return (int) $this->db()->fetchOne(
            "INSERT INTO item (letter, name, geom, country_code, region_id, state, source, source_ref, osm_ref, attributes, created_at, updated_at)
             VALUES (:letter, :name, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326), 'BE',
                     (SELECT id FROM region WHERE geom IS NOT NULL AND ST_Contains(geom, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326)) LIMIT 1),
                     :state, :source, :ref, :osm, CAST(:attrs AS jsonb), now(), now())
             RETURNING id",
            [
                'letter' => $letter, 'name' => $name, 'lon' => $lon, 'lat' => $lat, 'state' => $state, 'source' => $source,
                'ref' => $source.':'.bin2hex(random_bytes(6)), 'osm' => $osmRef,
                'attrs' => json_encode($attributes, \JSON_THROW_ON_ERROR),
            ],
        );
    }

    /**
     * @param list<array{0: float, 1: float}> $line
     * @param array<string, mixed>            $attributes
     */
    private function route(string $name, array $line, string $state = 'verified', array $attributes = [], ?int $proposedBy = null): int
    {
        return (int) $this->db()->fetchOne(
            "INSERT INTO recommended_route (name, geom, distance_m, ascent_m, region_id, state, source, source_ref, attributes, proposed_by, created_at, updated_at)
             VALUES (:name, ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326), 10000, 100,
                     (SELECT id FROM region WHERE geom IS NOT NULL AND ST_Intersects(geom, ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326)) LIMIT 1),
                     :state, 'user', :ref, CAST(:attrs AS jsonb), :by, now(), now())
             RETURNING id",
            [
                'name' => $name, 'geom' => json_encode(['type' => 'LineString', 'coordinates' => $line], \JSON_THROW_ON_ERROR),
                'state' => $state, 'ref' => 'user:'.bin2hex(random_bytes(6)), 'attrs' => json_encode($attributes, \JSON_THROW_ON_ERROR),
                'by' => $proposedBy,
            ],
        );
    }

    /** @return array<string, mixed> */
    private function getJson(KernelBrowser $client, string $url, int $status = 200): array
    {
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame($status, $url.' '.substr((string) $client->getResponse()->getContent(), 0, 300));
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $body;
    }
}
