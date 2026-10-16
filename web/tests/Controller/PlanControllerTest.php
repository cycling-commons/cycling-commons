<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Controller\PlanController;
use App\Elevation\ElevationClient;
use App\Elevation\LegSurfaces;
use App\Elevation\RouteSnapper;
use App\Entity\User;
use App\Tests\Coverage\CoverageSchema;
use App\Weather\Forecast;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * The planner's two server calls (docs/specs/route-planner.md section 8):
 * `POST /map/plan/leg` routes one leg for one bike and carries its heights,
 * `POST /map/plan/along` lists what the Commons has along the planned line.
 * Both follow the stateless-JSON pattern (security-architecture.md section
 * 5.1): a stateless `X-CC-Token`, a per-user limiter, and for a visitor a
 * limiter keyed on a salted hash of the address, never the address.
 */
final class PlanControllerTest extends WebTestCase
{
    use CoverageSchema;

    private static function user(string $email): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private static function token(string $id): string
    {
        return static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken($id)->getValue();
    }

    /** @param array<string, mixed> $body */
    private static function post(KernelBrowser $client, string $path, string $tokenId, array $body, ?string $token = null, string $site = 'same-origin'): void
    {
        $client->request('POST', $path, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CC_TOKEN' => $token ?? self::token($tokenId),
            'HTTP_SEC_FETCH_SITE' => $site,
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private static function leg(string $bike = 'Road'): array
    {
        // GeoJSON order, as /contribute/route takes its points.
        return ['a' => [5.8633, 50.4925], 'b' => [5.8627, 50.4745], 'bike' => $bike];
    }

    /** @return array<string, mixed> */
    private static function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private static function limit(string $limiter): int
    {
        $config = Yaml::parseFile(__DIR__.'/../../config/packages/rate_limiter.yaml');

        return (int) $config['framework']['rate_limiter'][$limiter]['limit'];
    }

    /* ---- the leg ---- */

    public function testAVisitorMayRouteALeg(): void
    {
        $client = static::createClient();
        self::post($client, '/map/plan/leg', 'plan-leg', self::leg());
        // No engine in the test env: an honest "no road", not an error.
        self::assertResponseIsSuccessful();
        self::assertFalse(self::json($client)['routed']);
    }

    public function testACrossSiteCallWithoutATokenIs403(): void
    {
        $client = static::createClient();
        self::post($client, '/map/plan/leg', 'plan-leg', self::leg(), token: '', site: 'cross-site');
        self::assertResponseStatusCodeSame(403);
    }

    public function testMalformedPointsAre400(): void
    {
        $client = static::createClient();
        self::post($client, '/map/plan/leg', 'plan-leg', ['a' => [5.8633], 'b' => 'x', 'bike' => 'Road']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testAnUnknownBikeIs400NeverTheEnginesDefault(): void
    {
        $client = static::createClient();
        self::post($client, '/map/plan/leg', 'plan-leg', self::leg('Unicycle'));
        self::assertResponseStatusCodeSame(400);
        self::assertSame('unknown_bike', self::json($client)['error']);
    }

    public function testARoutedLegCarriesItsLineDistanceAndHeights(): void
    {
        $client = static::createClient();
        $shape = self::encode([[50.4925, 5.8633], [50.4835, 5.8630], [50.4745, 5.8627]]);
        $engine = new MockHttpClient([
            new MockResponse((string) json_encode(['trip' => ['legs' => [['shape' => $shape, 'summary' => ['length' => 2.4]]]]])),
            new MockResponse((string) json_encode(['range_height' => [[0, 300], [30, 305], [60, 311]]])),
            new MockResponse((string) json_encode(['units' => 'kilometers', 'edges' => [['surface' => 'paved_smooth', 'length' => 2.0, 'names' => ['Avenue Reine Astrid']], ['surface' => 'gravel', 'length' => 0.4, 'names' => ['Chemin du Thier']]]])),
        ]);
        static::getContainer()->set(RouteSnapper::class, new RouteSnapper($engine, new NullLogger(), 'http://valhalla.test'));
        static::getContainer()->set(ElevationClient::class, new ElevationClient($engine, new NullLogger(), 'http://valhalla.test', 'Copernicus DEM GLO-30'));
        static::getContainer()->set(LegSurfaces::class, new LegSurfaces($engine, new NullLogger(), 'http://valhalla.test'));

        self::post($client, '/map/plan/leg', 'plan-leg', self::leg('Gravel'));

        self::assertResponseIsSuccessful();
        $json = self::json($client);
        self::assertTrue($json['routed']);
        self::assertCount(3, $json['coordinates']);
        self::assertEqualsWithDelta(5.8633, $json['coordinates'][0][0], 1e-6);
        // JSON carries a whole number without its fraction; the values are what count.
        self::assertEqualsWithDelta(2400.0, $json['distanceM'], 1e-6);
        self::assertEquals([[0, 300], [30, 305], [60, 311]], $json['heights']);
        self::assertSame(['paved' => 2000, 'gravel' => 400, 'rough' => 0, 'unknown' => 0, 'busy' => 0, 'cycle' => 0, 'signals' => 0], $json['surfaces']);
        self::assertSame([[0, 'Avenue Reine Astrid', 'road'], [2000, 'Chemin du Thier', 'road']], $json['streets'], 'the leg carries its street names, from our own engine');
    }

    /** The comparison leg of "Why this way?" asks the engine for the shortest way of the same bike; anything but `true` asks nothing extra. */
    public function testShortestTrueAsksTheEngineForTheShortestWayOfTheSameBike(): void
    {
        foreach ([[true, true], ['yes', false], [null, false]] as [$sent, $asked]) {
            $client = static::createClient();
            $bodies = [];
            $shape = self::encode([[50.4925, 5.8633], [50.4745, 5.8627]]);
            $engine = new MockHttpClient(static function (string $method, string $url, array $options) use (&$bodies, $shape): MockResponse {
                $bodies[] = json_decode((string) ($options['body'] ?? '{}'), true);

                return new MockResponse((string) json_encode(str_ends_with($url, '/route')
                    ? ['trip' => ['legs' => [['shape' => $shape, 'summary' => ['length' => 2.0]]]]]
                    : []));
            });
            static::getContainer()->set(RouteSnapper::class, new RouteSnapper($engine, new NullLogger(), 'http://valhalla.test'));

            $body = self::leg('Road');
            if (null !== $sent) {
                $body['shortest'] = $sent;
            }
            self::post($client, '/map/plan/leg', 'plan-leg', $body);

            self::assertResponseIsSuccessful();
            $options = $bodies[0]['costing_options']['bicycle'] ?? [];
            self::assertSame('Road', $options['bicycle_type'] ?? null);
            self::assertSame($asked, $options['shortest'] ?? false, 'shortest sent as '.var_export($sent, true));
            static::ensureKernelShutdown();
        }
    }

    public function testAVisitorOverTheDailyBudgetIs429(): void
    {
        $client = static::createClient();
        $secret = (string) static::getContainer()->getParameter('kernel.secret');
        $limiter = static::getContainer()->get('limiter.plan_leg_anon')->create(PlanController::anonKey('127.0.0.1', $secret));
        for ($i = 0, $n = self::limit('plan_leg_anon'); $i < $n; ++$i) {
            self::assertTrue($limiter->consume()->isAccepted());
        }

        self::post($client, '/map/plan/leg', 'plan-leg', self::leg());

        self::assertResponseStatusCodeSame(429);
    }

    public function testASignedInRiderSpendsTheirOwnBudgetNotTheVisitors(): void
    {
        $client = static::createClient();
        $user = self::user('plan-leg@test.test');
        $client->loginUser($user);
        $secret = (string) static::getContainer()->getParameter('kernel.secret');
        $anon = static::getContainer()->get('limiter.plan_leg_anon')->create(PlanController::anonKey('127.0.0.1', $secret));
        for ($i = 0, $n = self::limit('plan_leg_anon'); $i < $n; ++$i) {
            self::assertTrue($anon->consume()->isAccepted());
        }

        self::post($client, '/map/plan/leg', 'plan-leg', self::leg());

        self::assertResponseIsSuccessful();
    }

    public function testTheAnonymousKeyNeverContainsTheAddress(): void
    {
        $key = PlanController::anonKey('203.0.113.7', 'test-secret');
        self::assertStringNotContainsString('203.0.113.7', $key);
        self::assertNotSame($key, PlanController::anonKey('203.0.113.8', 'test-secret'));
    }

    /* ---- along the ride ---- */

    public function testAlongListsAnItemInsideTheCorridor(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::ensureCoverageSchema($em->getConnection());
        $tap = (new Item())->setLetter('B')->setName('Fontaine on the plan')
            ->setGeom(json_encode(['type' => 'Point', 'coordinates' => [5.8050, 50.40045]], \JSON_THROW_ON_ERROR))
            ->setCountryCode('BE')->setState(ItemState::Verified)->setSource(ItemSource::Osm)
            ->setSourceRef('node/plan-tap')->setAttributes([]);
        $em->persist($tap);
        $em->flush();

        self::post($client, '/map/plan/along', 'plan-along', ['line' => ['type' => 'LineString', 'coordinates' => [[5.8000, 50.4], [5.8300, 50.4]]]]);

        self::assertResponseIsSuccessful();
        $json = self::json($client);
        $water = array_values(array_filter($json['groups'], static fn (array $g): bool => 'B' === $g['letter']));
        self::assertCount(1, $water);
        self::assertContains($tap->getId(), array_column($water[0]['items'], 'id'));
    }

    /**
     * The along answer names the regions the planned line crosses, in ride
     * order, as the ride check does (owner 2026-10-16: a GPX of North Holland
     * opened while the map looked at Wallonia showed no places on the map,
     * because the map's scope stayed in Wallonia). The planner widens the
     * scope to them.
     */
    public function testAlongNamesTheRegionsTheLineCrosses(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::ensureCoverageSchema($db);
        $box = static fn (float $w, float $e): string => sprintf('POLYGON((%1$F 50.3,%1$F 50.5,%2$F 50.5,%2$F 50.3,%1$F 50.3))', $w, $e);
        $ids = [];
        foreach ([['plan-west', 'West plan province', 5.75, 5.815], ['plan-east', 'East plan province', 5.815, 5.90], ['plan-away', 'Far plan province', 6.50, 6.60]] as [$slug, $name, $w, $e]) {
            $db->executeStatement(
                "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
                 VALUES (?, ?, ST_GeomFromText(?, 4326), 1000, 'BE', ?, 4, 'test', NOW(), NOW())",
                [$slug, $name, $box($w, $e), strtoupper(substr($slug, 0, 7))],
            );
            $ids[$slug] = (int) $db->lastInsertId();
        }

        self::post($client, '/map/plan/along', 'plan-along', ['line' => ['type' => 'LineString', 'coordinates' => [[5.8000, 50.4], [5.8300, 50.4]]]]);

        self::assertResponseIsSuccessful();
        $regions = self::json($client)['regions'];
        self::assertSame([$ids['plan-west'], $ids['plan-east']], array_column($regions, 'id'), 'both crossed regions, in ride order, not the far one');
        self::assertSame(['BE', 'BE'], array_column($regions, 'countryCode'));
    }

    public function testAlongRefusesALineTooShortToRide(): void
    {
        $client = static::createClient();
        self::post($client, '/map/plan/along', 'plan-along', ['line' => ['type' => 'LineString', 'coordinates' => [[5.8000, 50.4], [5.8001, 50.4]]]]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testAlongRefusesANonLine(): void
    {
        $client = static::createClient();
        self::post($client, '/map/plan/along', 'plan-along', ['line' => 'LINESTRING(5.8 50.4, 5.83 50.4)']);
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * Encode [lat,lng] pairs the way the engine does (polyline, 1e-6).
     *
     * @param list<array{0: float, 1: float}> $points
     */
    private static function encode(array $points): string
    {
        $out = '';
        $prevLat = $prevLng = 0;
        foreach ($points as [$lat, $lng]) {
            foreach ([(int) round($lat * 1e6) - $prevLat, (int) round($lng * 1e6) - $prevLng] as $delta) {
                $v = $delta < 0 ? ~($delta << 1) : ($delta << 1);
                while ($v >= 0x20) {
                    $out .= \chr((0x20 | ($v & 0x1F)) + 63);
                    $v >>= 5;
                }
                $out .= \chr($v + 63);
            }
            $prevLat = (int) round($lat * 1e6);
            $prevLng = (int) round($lng * 1e6);
        }

        return $out;
    }

    /* ---- the forecast (route-planner.md section 4.4, section 8) ---- */

    /** @return array<string, mixed> */
    private static function weatherAsk(): array
    {
        return ['lat' => 50.4825, 'lng' => 5.8630, 'date' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d')];
    }

    public function testTheForecastNeedsTheTokenAndAWellFormedAsk(): void
    {
        $client = static::createClient();
        self::post($client, '/map/plan/weather', 'plan-weather', self::weatherAsk(), 'not-a-token');
        self::assertResponseStatusCodeSame(403);

        self::post($client, '/map/plan/weather', 'plan-weather', ['lat' => 'x', 'lng' => 5.86, 'date' => '2026-10-18']);
        self::assertResponseStatusCodeSame(400);
        self::post($client, '/map/plan/weather', 'plan-weather', ['lat' => 50.48, 'lng' => 5.86, 'date' => '18/10/2026']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testWithoutAForecastServiceTheAnswerIsNullNeverAnError(): void
    {
        $client = static::createClient();

        self::post($client, '/map/plan/weather', 'plan-weather', self::weatherAsk());

        self::assertResponseIsSuccessful();
        self::assertNull(self::json($client)['forecast'], 'the test environment has no forecast service, as it has no engine');
    }

    public function testTheForecastIsPassedThroughForTheRoundedPoint(): void
    {
        $client = static::createClient();
        $asked = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$asked): MockResponse {
            $asked[] = $url;

            return new MockResponse((string) json_encode(['daily' => [
                'time' => [self::weatherAsk()['date']], 'wind_speed_10m_max' => [28.1], 'wind_direction_10m_dominant' => [225], 'temperature_2m_max' => [31.4], 'precipitation_sum' => [6.2],
            ]]));
        });
        static::getContainer()->set(Forecast::class, new Forecast($http, new ArrayAdapter(), new NullLogger(), static::getContainer()->get(ClockInterface::class), 'https://forecast.test'));

        self::post($client, '/map/plan/weather', 'plan-weather', self::weatherAsk());

        self::assertResponseIsSuccessful();
        self::assertSame(['date' => self::weatherAsk()['date'], 'windKmh' => 28.1, 'windFromDeg' => 225, 'tempMaxC' => 31.4, 'rainMm' => 6.2], self::json($client)['forecast']);
        self::assertCount(1, $asked);
        self::assertStringContainsString('latitude=50.5&longitude=5.9', $asked[0], 'the point leaves our server rounded to a tenth of a degree');
        self::assertStringNotContainsString('50.48', $asked[0]);
    }

    public function testAVisitorOverTheForecastBudgetIs429(): void
    {
        $client = static::createClient();
        $secret = (string) static::getContainer()->getParameter('kernel.secret');
        $limiter = static::getContainer()->get('limiter.plan_weather_anon')->create(PlanController::anonKey('127.0.0.1', $secret));
        for ($i = 0, $n = self::limit('plan_weather_anon'); $i < $n; ++$i) {
            self::assertTrue($limiter->consume()->isAccepted());
        }

        self::post($client, '/map/plan/weather', 'plan-weather', self::weatherAsk());

        self::assertResponseStatusCodeSame(429);
    }
}
