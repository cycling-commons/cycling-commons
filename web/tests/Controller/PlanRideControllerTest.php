<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\Planner\RideFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Saved rides (docs/specs/route-planner.md section 6.1 and 8): a signed-in
 * rider saves, opens, lists and deletes their own rides as JSON, and nobody
 * else's. Every call follows the stateless-JSON pattern
 * (security-architecture.md section 5.1): a clean 401 for a visitor, a
 * stateless `X-CC-Token` on the writes, a 403 for a bad or cross-site token,
 * cheap validation before any budget is spent.
 */
final class PlanRideControllerTest extends WebTestCase
{
    use RideFixtures;

    private static function user(string $email): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private static function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
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
    private static function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private static function limit(string $limiter): int
    {
        $config = Yaml::parseFile(__DIR__.'/../../config/packages/rate_limiter.yaml');

        return (int) $config['framework']['rate_limiter'][$limiter]['limit'];
    }

    private static function ridesOf(int $userId): int
    {
        return (int) self::db()->fetchOne('SELECT COUNT(*) FROM planned_ride WHERE user_id = ?', [$userId]);
    }

    /** The map template links these by name; a rename here is a broken planner. */
    public function testTheRouteNamesAreTheOnesTheMapTemplateReferences(): void
    {
        self::bootKernel();
        $router = static::getContainer()->get('router');

        self::assertSame('/map/plan/save', $router->generate('map_plan_save'));
        self::assertSame('/map/plan/ride/7', $router->generate('map_plan_ride', ['id' => 7]));
        self::assertSame('/map/plan/rides', $router->generate('map_plan_rides'));
        self::assertSame('/map/plan/ride/7/delete', $router->generate('map_plan_ride_delete', ['id' => 7]));
    }

    /* ---- scope: a ride is its rider's and nobody else's ---- */

    public function testARidersRideIsNeverSeenChangedOrDeletedByAnotherRiderOrAVisitor(): void
    {
        $client = static::createClient();
        $a = self::user('plan-ride-a@test.test');
        $b = self::user('plan-ride-b@test.test');
        $id = self::seedRide(self::db(), (int) $a->getId());

        // A visitor: a clean 401 on every call, never a redirect to the login page.
        $client->request('GET', '/map/plan/ride/'.$id);
        self::assertResponseStatusCodeSame(401);
        $client->request('GET', '/map/plan/rides');
        self::assertResponseStatusCodeSame(401);
        self::post($client, '/map/plan/save', 'plan-save', self::body(self::db(), ['id' => $id, 'name' => 'Taken over']));
        self::assertResponseStatusCodeSame(401);
        self::post($client, '/map/plan/ride/'.$id.'/delete', 'plan-delete', []);
        self::assertResponseStatusCodeSame(401);

        // Rider B: the ride does not exist.
        $client->loginUser($b);
        $client->request('GET', '/map/plan/ride/'.$id);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/map/plan/rides');
        self::assertResponseIsSuccessful();
        self::assertSame([], self::json($client)['rides']);
        self::post($client, '/map/plan/save', 'plan-save', self::body(self::db(), ['id' => $id, 'name' => 'Taken over']));
        self::assertResponseStatusCodeSame(404);
        self::post($client, '/map/plan/ride/'.$id.'/delete', 'plan-delete', []);
        self::assertResponseStatusCodeSame(404);

        // The ride is as rider A left it, and B's attempts added nothing.
        self::assertSame('Ride of A', self::db()->fetchOne('SELECT name FROM planned_ride WHERE id = ?', [$id]));
        self::assertSame(1, self::ridesOf((int) $a->getId()));
        self::assertSame(0, self::ridesOf((int) $b->getId()));

        // Rider A sees it.
        $client->loginUser($a);
        $client->request('GET', '/map/plan/ride/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSame('Ride of A', self::json($client)['name']);
    }

    /* ---- save ---- */

    public function testSaveInsertsARideAndAnswersItsId(): void
    {
        $client = static::createClient();
        $rider = self::user('plan-save@test.test');
        $client->loginUser($rider);

        self::post($client, '/map/plan/save', 'plan-save', self::body(self::db()));

        self::assertResponseIsSuccessful();
        $id = self::json($client)['id'];
        self::assertIsInt($id);
        $row = self::db()->fetchAssociative(
            'SELECT user_id, name, bike, stops::text AS stops, ST_NPoints(line) AS points, distance_m, ascent_m, descent_m FROM planned_ride WHERE id = ?',
            [$id],
        );
        self::assertIsArray($row);
        self::assertSame((int) $rider->getId(), (int) $row['user_id']);
        self::assertSame('Sunday loop', $row['name']);
        self::assertSame('Road', $row['bike']);
        self::assertSame(3, (int) $row['points']);
        self::assertSame(120, (int) $row['ascent_m']);
        self::assertSame(118, (int) $row['descent_m']);
        self::assertCount(2, json_decode((string) $row['stops'], true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testSaveWithAnIdUpdatesTheRideAndAddsNothing(): void
    {
        $client = static::createClient();
        $rider = self::user('plan-update@test.test');
        $client->loginUser($rider);
        $id = self::seedRide(self::db(), (int) $rider->getId(), 'First name', "NOW() - INTERVAL '1 day'");

        self::post($client, '/map/plan/save', 'plan-save', self::body(self::db(), ['id' => $id, 'name' => 'Second name', 'bike' => 'MTB']));

        self::assertResponseIsSuccessful();
        self::assertSame($id, self::json($client)['id']);
        self::assertSame(1, self::ridesOf((int) $rider->getId()));
        $row = self::db()->fetchAssociative("SELECT name, bike, (updated_at > NOW() - INTERVAL '12 hours') AS touched FROM planned_ride WHERE id = ?", [$id]);
        self::assertIsArray($row);
        self::assertSame('Second name', $row['name']);
        self::assertSame('MTB', $row['bike']);
        self::assertTrue((bool) $row['touched'], 'updated_at moved from a day ago');
    }

    public function testACrossSiteSaveWithoutATokenIs403(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('plan-token@test.test'));

        self::post($client, '/map/plan/save', 'plan-save', self::body(self::db()), token: '', site: 'cross-site');

        self::assertResponseStatusCodeSame(403);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidBodies(): iterable
    {
        yield 'an empty name' => [['name' => '   ']];
        yield 'a name past 80 characters' => [['name' => str_repeat('x', 81)]];
        yield 'an unknown bike' => [['bike' => 'Unicycle']];
        yield 'one stop' => [['stops' => [['lat' => 50.4925, 'lng' => 5.8633]]]];
        yield '26 stops' => [['stops' => array_fill(0, 26, ['lat' => 50.4925, 'lng' => 5.8633])]];
        yield 'a stop off the planet' => [['stops' => [['lat' => 95.0, 'lng' => 5.8633], ['lat' => 50.4745, 'lng' => 5.8627]]]];
        yield 'a line with one point' => [['line' => ['type' => 'LineString', 'coordinates' => [[5.8633, 50.4925]]]]];
        yield 'a line that is not a LineString' => [['line' => ['type' => 'Point', 'coordinates' => [5.8633, 50.4925]]]];
        yield 'a negative ascent' => [['ascentM' => -1]];
        yield 'a distance that is not a number' => [['distanceM' => 'far']];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidBodies')]
    public function testAnInvalidFieldIs400(array $overrides): void
    {
        $client = static::createClient();
        $rider = self::user('plan-invalid@test.test');
        $client->loginUser($rider);

        self::post($client, '/map/plan/save', 'plan-save', self::body(self::db(), $overrides));

        self::assertResponseStatusCodeSame(400);
        self::assertSame('bad_request', self::json($client)['error']);
        self::assertSame(0, self::ridesOf((int) $rider->getId()));
    }

    public function testADistanceTenPercentOffTheLineIsALengthMismatch(): void
    {
        $client = static::createClient();
        $rider = self::user('plan-length@test.test');
        $client->loginUser($rider);
        $length = self::lengthOf(self::db(), self::line());

        self::post($client, '/map/plan/save', 'plan-save', self::body(self::db(), ['distanceM' => (int) round($length * 1.1)]));

        self::assertResponseStatusCodeSame(400);
        self::assertSame('length_mismatch', self::json($client)['error']);
        self::assertSame(0, self::ridesOf((int) $rider->getId()));
    }

    public function testTheHundredAndFirstRideIs409ButAnUpdateStillLands(): void
    {
        $client = static::createClient();
        $rider = self::user('plan-cap@test.test');
        $client->loginUser($rider);
        self::seedRides(self::db(), (int) $rider->getId(), 100);

        self::post($client, '/map/plan/save', 'plan-save', self::body(self::db()));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('too_many_rides', self::json($client)['error']);
        self::assertSame(100, self::ridesOf((int) $rider->getId()));

        $one = (int) self::db()->fetchOne('SELECT MIN(id) FROM planned_ride WHERE user_id = ?', [(int) $rider->getId()]);
        self::post($client, '/map/plan/save', 'plan-save', self::body(self::db(), ['id' => $one, 'name' => 'Still mine']));

        self::assertResponseIsSuccessful();
        self::assertSame(100, self::ridesOf((int) $rider->getId()));
    }

    /**
     * The budget is drained by direct limiter calls and the test then spends
     * exactly one request, the first it makes: the kernel reboots between
     * requests and the array pool goes with it.
     */
    public function testARiderOverTheSaveBudgetIs429(): void
    {
        $client = static::createClient();
        $rider = self::user('plan-budget@test.test');
        $client->loginUser($rider);
        $limiter = static::getContainer()->get('limiter.plan_save')->create('user-'.(string) $rider->getId());
        for ($i = 0, $n = self::limit('plan_save'); $i < $n; ++$i) {
            self::assertTrue($limiter->consume()->isAccepted());
        }

        self::post($client, '/map/plan/save', 'plan-save', self::body(self::db()));

        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', self::json($client)['error']);
        self::assertSame(0, self::ridesOf((int) $rider->getId()));
    }

    /* ---- open and list ---- */

    public function testASavedRideOpensWithItsLineStopsAndFigures(): void
    {
        $client = static::createClient();
        $rider = self::user('plan-open@test.test');
        $client->loginUser($rider);
        $id = self::seedRide(self::db(), (int) $rider->getId());

        $client->request('GET', '/map/plan/ride/'.$id);

        self::assertResponseIsSuccessful();
        $json = self::json($client);
        self::assertSame($id, $json['id']);
        self::assertSame('Ride of A', $json['name']);
        self::assertSame('Gravel', $json['bike']);
        self::assertSame('LineString', $json['line']['type']);
        self::assertCount(3, $json['line']['coordinates']);
        self::assertEqualsWithDelta(5.8633, $json['line']['coordinates'][0][0], 1e-6);
        self::assertSame('Fontaine', $json['stops'][1]['name']);
        self::assertSame('B:12', $json['stops'][1]['item']);
        self::assertEqualsWithDelta(self::lengthOf(self::db(), self::line()), $json['distanceM'], 1.0);
        self::assertSame(120, $json['ascentM']);
        self::assertSame(118, $json['descentM']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $json['updatedAt']), 'updatedAt is ISO 8601');
    }

    public function testTheListIsNewestUpdatedFirstWithTheFiguresAndNoLine(): void
    {
        $client = static::createClient();
        $rider = self::user('plan-list@test.test');
        $client->loginUser($rider);
        $older = self::seedRide(self::db(), (int) $rider->getId(), 'Older', "NOW() - INTERVAL '2 days'");
        $newer = self::seedRide(self::db(), (int) $rider->getId(), 'Newer', "NOW() - INTERVAL '1 day'");

        $client->request('GET', '/map/plan/rides');

        self::assertResponseIsSuccessful();
        $rides = self::json($client)['rides'];
        self::assertSame([$newer, $older], array_column($rides, 'id'));
        self::assertSame(['Newer', 'Older'], array_column($rides, 'name'));
        self::assertSame('Gravel', $rides[0]['bike']);
        self::assertCount(2, $rides[0]['stops']);
        self::assertSame(120, $rides[0]['ascentM']);
        self::assertSame(118, $rides[0]['descentM']);
        self::assertIsInt($rides[0]['distanceM']);
        self::assertArrayHasKey('updatedAt', $rides[0]);
        self::assertArrayNotHasKey('line', $rides[0]);
    }

    /* ---- delete ---- */

    public function testDeleteIs204AndTheRideIsGone(): void
    {
        $client = static::createClient();
        $rider = self::user('plan-delete@test.test');
        $client->loginUser($rider);
        $id = self::seedRide(self::db(), (int) $rider->getId());

        self::post($client, '/map/plan/ride/'.$id.'/delete', 'plan-delete', []);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, self::ridesOf((int) $rider->getId()));

        $client->request('GET', '/map/plan/ride/'.$id);
        self::assertResponseStatusCodeSame(404);
    }

    public function testACrossSiteDeleteWithoutATokenIs403AndDeletesNothing(): void
    {
        $client = static::createClient();
        $rider = self::user('plan-delete-token@test.test');
        $client->loginUser($rider);
        $id = self::seedRide(self::db(), (int) $rider->getId());

        self::post($client, '/map/plan/ride/'.$id.'/delete', 'plan-delete', [], token: '', site: 'cross-site');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, self::ridesOf((int) $rider->getId()));
    }
}
