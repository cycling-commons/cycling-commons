<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A /v1 path the contract describes but nobody built yet answers 501 in JSON
 * and says so; any other /v1 path answers a JSON 404. Both name what does
 * answer today and where the contract lives (public-api.md §2.3).
 *
 * Before this, `/v1/regions` (in openapi.yaml since the design) was the site's
 * HTML 404 page: a consumer reading the docs could not tell "planned" from
 * "wrong URL" (GlitchTip, 2026-09-28).
 */
final class PlannedEndpointTest extends WebTestCase
{
    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        return (array) json_decode((string) $client->getResponse()->getContent(), true);
    }

    public function testAPlannedEndpointSaysItIsNotBuiltYet(): void
    {
        $client = static::createClient();
        $client->request('GET', '/v1/regions');

        self::assertResponseStatusCodeSame(501);
        $body = $this->json($client);
        self::assertSame('not_implemented', $body['error']);
        self::assertStringContainsString('GET /v1/regions', (string) $body['message']);
        self::assertStringContainsString('not built yet', (string) $body['message']);
        self::assertSame(['/v1/map-config', '/v1/search'], $body['live']);
        self::assertSame('http://localhost/developers/api', $body['reference']);
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', '*');
    }

    /** Path templates in the contract match real ids, and a literal `.gpx`. */
    public function testATemplatedPlannedPathMatches(): void
    {
        $client = static::createClient();
        foreach (['/v1/regions/12/best', '/v1/items/345', '/v1/routes/7.gpx'] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(501, $path);
        }
    }

    public function testAPlannedWriteEndpointSaysSoToo(): void
    {
        $client = static::createClient();
        $client->request('POST', '/v1/contributions');

        self::assertResponseStatusCodeSame(501);
        self::assertStringContainsString('POST /v1/contributions', (string) $this->json($client)['message']);
    }

    public function testAPathTheContractDoesNotDescribeIsAJsonNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/v1/credentials.yml');

        self::assertResponseStatusCodeSame(404);
        $body = $this->json($client);
        self::assertSame('not_found', $body['error']);
        self::assertSame(['/v1/map-config', '/v1/search'], $body['live']);
    }

    public function testALiveEndpointStillAnswers(): void
    {
        $client = static::createClient();
        $client->request('GET', '/v1/map-config');

        self::assertResponseIsSuccessful();
    }
}
