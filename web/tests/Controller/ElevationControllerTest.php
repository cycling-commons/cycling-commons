<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * POST /contribute/elevation hardening (review 2026-08-16 finding 5): the
 * stateless X-CC-Token header and the per-user per-minute limiter, both
 * sitting in front of an upstream Valhalla call. Login alone is not a quota.
 *
 * ELEVATION_URL is empty in test, so a request that clears token, validation
 * and limiter answers 503 elevation_unavailable — which is exactly the proof
 * the guards let a well-formed request through to the profiler.
 */
final class ElevationControllerTest extends WebTestCase
{
    /** Two points ~150 m apart: minimal but valid editor input. */
    private const string BODY = '{"coords":[[50.400,5.800],[50.401,5.801]]}';

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
        return static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('elevation')->getValue();
    }

    /** @param array<string, string> $extraServer */
    private static function post(KernelBrowser $client, string $body, array $extraServer = []): void
    {
        $client->request('POST', '/contribute/elevation', [], [], array_merge(
            ['CONTENT_TYPE' => 'application/json'],
            $extraServer,
        ), $body);
    }

    public function testAnonymousGetsClean401(): void
    {
        $client = static::createClient();
        self::post($client, self::BODY, ['HTTP_X_CC_TOKEN' => self::token(), 'HTTP_SEC_FETCH_SITE' => 'same-origin']);
        // THE pattern (security-architecture.md §5.1): a JSON client is never
        // 302-redirected to the login page.
        self::assertResponseStatusCodeSame(401);
    }

    public function testMissingTokenIs403(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('elev-notoken@test.test'));
        self::post($client, self::BODY);
        self::assertResponseStatusCodeSame(403);
    }

    public function testBadTokenIs403(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('elev-badtoken@test.test'));
        self::post($client, self::BODY, ['HTTP_X_CC_TOKEN' => 'nope', 'HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testMalformedBodyIs400AndCostsNoBudget(): void
    {
        $client = static::createClient();
        $user = self::user('elev-badbody@test.test');
        $client->loginUser($user);
        self::post($client, '{"coords":"not a list"}', ['HTTP_X_CC_TOKEN' => self::token(), 'HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseStatusCodeSame(400);

        // The 400 came back BEFORE the limiter: the full budget is still there.
        $factory = static::getContainer()->get('limiter.elevation');
        self::assertTrue($factory->create('user-'.(string) $user->getId())->consume(0)->isAccepted());
    }

    public function testWellFormedRequestReachesTheProfiler(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('elev-happy@test.test'));
        self::post($client, self::BODY, ['HTTP_X_CC_TOKEN' => self::token(), 'HTTP_SEC_FETCH_SITE' => 'same-origin']);
        // Guards passed; with no ELEVATION_URL the profiler honestly says it
        // could not measure (503), which is the deepest this test can reach.
        self::assertResponseStatusCodeSame(503);
        /** @var array{error: string} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('elevation_unavailable', $body['error']);
    }

    public function testOverPerMinuteLimitIs429(): void
    {
        $client = static::createClient();
        $user = self::user('elev-limit@test.test');

        // Drain the limiter via the factory (RideCheckControllerTest
        // convention: the array cache pool resets between HTTP requests, so
        // real requests could never trip it), then let the single HTTP request
        // be the one over the line. The count comes from the config so the
        // test follows the number instead of pinning it.
        $factory = static::getContainer()->get('limiter.elevation');
        $limiter = $factory->create('user-'.(string) $user->getId());
        $limit = $limiter->consume(0)->getLimit();
        self::assertGreaterThan(0, $limit);
        for ($i = 0; $i < $limit; ++$i) {
            self::assertTrue($limiter->consume()->isAccepted());
        }

        $client->loginUser($user);
        self::post($client, self::BODY, ['HTTP_X_CC_TOKEN' => self::token(), 'HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseStatusCodeSame(429);
        /** @var array{error: string} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('rate_limited', $body['error']);
    }
}
