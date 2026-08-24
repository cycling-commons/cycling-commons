<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * POST /contribute/route hardening (test-suite review 2026-08-24).
 *
 * The road-snap endpoint is the twin of /contribute/elevation: same two editor
 * pages, same upstream Valhalla, same stateless-JSON contract. It shipped with
 * `#[IsGranted('ROLE_USER')]` as its only guard and no HTTP test of any kind,
 * so nothing pinned that an anonymous caller gets a clean 401 instead of a 302
 * to the login page, and nothing stopped one account from spending the routing
 * box's whole capacity.
 *
 * This mirrors ElevationControllerTest case for case on purpose: the two
 * endpoints must not drift, and a reader comparing them should see one shape.
 *
 * VALHALLA_URL is empty in test, so a request that clears token, validation and
 * limiter answers `NoRoute` — which is the proof the guards let a well-formed
 * request through to the snapper.
 *
 * @see docs/specs/security-architecture.md §5.1
 * @see docs/specs/climb-elevation.md §3e
 */
final class RouteControllerTest extends WebTestCase
{
    /** Two points ~150 m apart: minimal but valid editor input, [lng, lat]. */
    private const string BODY = '{"a":[5.800,50.400],"b":[5.801,50.401]}';

    private static function user(string $email): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private static function token(): string
    {
        return static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('route-snap')->getValue();
    }

    /** @param array<string, string> $extraServer */
    private static function post(KernelBrowser $client, string $body, array $extraServer = []): void
    {
        $client->request('POST', '/contribute/route', [], [], array_merge(
            ['CONTENT_TYPE' => 'application/json'],
            $extraServer,
        ), $body);
    }

    /** @return array<string, string> */
    private static function goodHeaders(): array
    {
        return ['HTTP_X_CC_TOKEN' => self::token(), 'HTTP_SEC_FETCH_SITE' => 'same-origin'];
    }

    public function testAnonymousGetsClean401(): void
    {
        $client = static::createClient();
        self::post($client, self::BODY, self::goodHeaders());
        // THE pattern (security-architecture.md §5.1): a JSON client is never
        // 302-redirected to the login page. `#[IsGranted]` alone did exactly
        // that here until 2026-08-24.
        self::assertResponseStatusCodeSame(401);
    }

    public function testMissingTokenIs403(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('snap-notoken@test.test'));
        self::post($client, self::BODY);
        self::assertResponseStatusCodeSame(403);
    }

    public function testBadTokenIs403(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('snap-badtoken@test.test'));
        self::post($client, self::BODY, ['HTTP_X_CC_TOKEN' => 'nope', 'HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testMissingLegIs400(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('snap-oneleg@test.test'));
        // Only `a`: a snap needs both ends, and half a leg must not reach Valhalla.
        self::post($client, '{"a":[5.800,50.400]}', self::goodHeaders());
        self::assertResponseStatusCodeSame(400);
    }

    public function testNonNumericCoordinateIs400(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('snap-badcoord@test.test'));
        self::post($client, '{"a":["x","y"],"b":[5.801,50.401]}', self::goodHeaders());
        self::assertResponseStatusCodeSame(400);
    }

    public function testOutOfRangeCoordinateIs400(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('snap-outofrange@test.test'));
        // Latitude 95 is off the planet; the range check is the last line
        // before an upstream call.
        self::post($client, '{"a":[5.800,95.0],"b":[5.801,50.401]}', self::goodHeaders());
        self::assertResponseStatusCodeSame(400);
    }

    public function testMalformedBodyIs400AndCostsNoBudget(): void
    {
        $client = static::createClient();
        $user = self::user('snap-badbody@test.test');
        $client->loginUser($user);
        self::post($client, '{"a":"not a point","b":"neither"}', self::goodHeaders());
        self::assertResponseStatusCodeSame(400);

        // The 400 came back BEFORE the limiter: the full budget is still there.
        $factory = static::getContainer()->get('limiter.route_snap');
        self::assertTrue($factory->create('user-'.(string) $user->getId())->consume(0)->isAccepted());
    }

    public function testWellFormedRequestReachesTheSnapper(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('snap-happy@test.test'));
        self::post($client, self::BODY, self::goodHeaders());
        // Guards passed; with no Valhalla configured the snapper reports that
        // it found no road, which is an answer and not an error (the editor
        // keeps the straight line).
        self::assertResponseIsSuccessful();
        /** @var array{code: string, routes: array<mixed>} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('NoRoute', $body['code']);
        self::assertSame([], $body['routes']);
    }

    public function testOverPerMinuteLimitIs429(): void
    {
        $client = static::createClient();
        $user = self::user('snap-limit@test.test');

        // Drain via the factory, exactly as ElevationControllerTest does: the
        // array cache pool resets between HTTP requests in test, so real
        // requests could never trip the limiter. The count comes from config so
        // the test follows the number instead of pinning it.
        $factory = static::getContainer()->get('limiter.route_snap');
        $limiter = $factory->create('user-'.(string) $user->getId());
        $limit = $limiter->consume(0)->getLimit();
        self::assertGreaterThan(0, $limit);
        for ($i = 0; $i < $limit; ++$i) {
            self::assertTrue($limiter->consume()->isAccepted());
        }

        $client->loginUser($user);
        self::post($client, self::BODY, self::goodHeaders());
        self::assertResponseStatusCodeSame(429);
        /** @var array{error: string} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('rate_limited', $body['error']);
    }

    /**
     * The endpoint is deliberately absent from security.yaml's access_control
     * backstop list, for the same reason /contribute/elevation is: a rule there
     * would turn the clean 401 above back into a 302. That exemption is only
     * safe while the controller enforces auth itself, so pin that it does.
     */
    public function testGetIsNotRoutedAtAll(): void
    {
        $client = static::createClient();
        $client->request('GET', '/contribute/route');
        self::assertResponseStatusCodeSame(405);
    }
}
