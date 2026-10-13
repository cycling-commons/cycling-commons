<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Api;

use App\Api\ApiSurface;
use App\Controller\Api\V1\PlannedApiController;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * A /v1 path no endpoint claims answers in JSON, never the site's HTML 404
 * page: 501 when the contract describes it (planned, not built), a 404
 * otherwise. Both name what does answer today and where the contract lives
 * (public-api.md §2.3).
 *
 * Every path of today's contract is built (ContractTest), so the 501 is
 * proven against a contract that promises one more path than exists.
 */
final class PlannedEndpointTest extends WebTestCase
{
    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        return (array) json_decode((string) $client->getResponse()->getContent(), true);
    }

    public function testAPathTheContractDoesNotDescribeIsAJsonNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/v1/credentials.yml');

        self::assertResponseStatusCodeSame(404);
        $body = $this->json($client);
        self::assertSame('not_found', $body['error']);
        self::assertSame(static::getContainer()->get(ApiSurface::class)->livePaths(), $body['live']);
        self::assertContains('/v1/search', $body['live']);
        self::assertSame('http://localhost/developers/api', $body['reference']);
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', '*');
    }

    public function testAMethodTheContractDoesNotDescribeIsAJsonNotFound(): void
    {
        $client = static::createClient();
        $client->request('PUT', '/v1/contributions');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('not_found', $this->json($client)['error']);
    }

    /** A built endpoint with an id its route refuses is a wrong address, never "not built yet". */
    public function testAWrongIdOnABuiltEndpointIsANotFound(): void
    {
        $client = static::createClient();
        foreach (['/v1/items/abc', '/v1/regions/Wallonia', '/v1/routes/x.gpx'] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
            self::assertSame('not_found', $this->json($client)['error'], $path);
        }
    }

    public function testAPlannedEndpointSaysItIsNotBuiltYet(): void
    {
        self::bootKernel();
        $contract = sys_get_temp_dir().'/planned-contract-'.getmypid().'.yaml';
        file_put_contents($contract, "paths:\n  /v1/planned/{id}/thing:\n    get: {}\n  /v1/routes/{id}.gpx:\n    get: {}\n");
        $surface = new ApiSurface(self::getContainer()->get(RouterInterface::class), $contract);

        self::assertTrue($surface->describes('GET', '/v1/planned/12/thing'), '{id} matches one segment');
        self::assertTrue($surface->describes('GET', '/v1/routes/7.gpx'), 'and the rest literally');
        self::assertFalse($surface->describes('POST', '/v1/planned/12/thing'));

        $controller = new PlannedApiController($surface, 'http://localhost');
        $controller->setContainer(self::getContainer());
        $response = $controller->fallback(Request::create('/v1/planned/12/thing'));
        unlink($contract);

        self::assertSame(501, $response->getStatusCode());
        $body = (array) json_decode((string) $response->getContent(), true);
        self::assertSame('not_implemented', $body['error']);
        self::assertStringContainsString('GET /v1/planned/12/thing', (string) $body['message']);
    }

    public function testALiveEndpointStillAnswers(): void
    {
        $client = static::createClient();
        $client->request('GET', '/v1/map-config');

        self::assertResponseIsSuccessful();
    }
}
