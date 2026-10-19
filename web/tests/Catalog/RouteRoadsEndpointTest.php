<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Elevation\LegSurfaces;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * GET /map/route/{id}/roads (docs/specs/route-domain.md §6.4, owner 2026-10-19: "A recommended route should also have
 * the road 'quality' info. Like when you plan your own route"): what the route's roads are, read by our own engine
 * along the route's own line, as a planned leg is. Public and cached for a live route; a route waiting for review is
 * 404 to anyone who may not preview it, as the climbs and the along list are.
 */
final class RouteRoadsEndpointTest extends WebTestCase
{
    private function seedRoute(string $state): int
    {
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement(
            "INSERT INTO recommended_route (name, geom, distance_m, ascent_m, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('Roads route', ST_SetSRID(ST_GeomFromText('LINESTRING(5.80 50.40, 5.83 50.40)'), 4326), 2130, 0, :state, 'user', :ref, '{}', NOW(), NOW())",
            ['state' => $state, 'ref' => 'user:roads-'.bin2hex(random_bytes(6))],
        );

        return (int) $db->fetchOne('SELECT MAX(id) FROM recommended_route');
    }

    public function testALiveRouteSaysWhatItsRoadsAreAndIsCachedPublicly(): void
    {
        $client = static::createClient();
        $engine = new MockHttpClient(new MockResponse((string) json_encode(['units' => 'kilometers', 'edges' => [
            ['way_id' => 1, 'surface' => 'paved_smooth', 'length' => 1.5, 'road_class' => 'secondary', 'speed_limit' => 70],
            ['way_id' => 2, 'surface' => 'gravel', 'length' => 0.6, 'use' => 'track'],
        ]])));
        static::getContainer()->set(LegSurfaces::class, new LegSurfaces($engine, new NullLogger(), 'http://valhalla.test'));
        $id = $this->seedRoute('verified');

        $client->request('GET', '/map/route/'.$id.'/roads');

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['paved' => 1500, 'gravel' => 600, 'rough' => 0, 'unknown' => 0, 'busy' => 1500, 'cycle' => 0, 'signals' => 0], $body['surfaces']);
        self::assertSame(2100, $body['distanceM']);
        self::assertSame(['70' => 1500, 'unset' => 600], $body['speeds']);
        self::assertSame([[0, 1500, '70'], [1500, 2100, 'unset']], $body['detailRuns']['speeds']);
        self::assertSame([[0, 'paved'], [1500, 'gravel']], $body['runs']);
        self::assertSame([[0, 1500]], $body['busyRuns']);
        self::assertArrayNotHasKey('streets', $body, 'the route card names no streets');
        self::assertStringContainsString('public', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testARouteWaitingForReviewIsNotFoundForAGuest(): void
    {
        $client = static::createClient();
        $id = $this->seedRoute('submitted');

        $client->request('GET', '/map/route/'.$id.'/roads');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNoEngineIsNotFound(): void
    {
        $client = static::createClient();
        static::getContainer()->set(LegSurfaces::class, new LegSurfaces(new MockHttpClient(new MockResponse('', ['error' => 'down'])), new NullLogger(), 'http://valhalla.test'));
        $id = $this->seedRoute('verified');

        $client->request('GET', '/map/route/'.$id.'/roads');

        self::assertResponseStatusCodeSame(404);
    }
}
