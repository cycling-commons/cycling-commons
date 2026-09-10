<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The interactive API reference (/developers/api) and its design-first
 * OpenAPI contract (public/api/openapi.yaml). The contract is the
 * machine-readable companion to docs/specs/public-api.md; the page renders it
 * with Redoc. The consistency test pins the /developers teaser page to the
 * contract so the two cannot drift apart again.
 */
final class ApiReferenceTest extends WebTestCase
{
    public function testReferencePageRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/developers/api');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('redoc-standalone', $html);   // vendored, digested filename
        self::assertStringContainsString('/api/openapi.yaml', $html);
        // English-only by design: no locale-prefixed variant exists.
        $client->request('GET', '/fr/developers/api');
        self::assertResponseStatusCodeSame(404);
    }

    public function testContractParsesAndCoversTheCore(): void
    {
        $doc = Yaml::parseFile(__DIR__.'/../../public/api/openapi.yaml');

        self::assertSame('3.1.0', $doc['openapi']);
        foreach ([
            '/v1/catalog', '/v1/search', '/v1/items/{id}', '/v1/coverage/counts',
            '/v1/regions', '/v1/regions/{id}', '/v1/regions/{id}/best',
            '/v1/routes', '/v1/routes/{id}', '/v1/routes/{id}.gpx',
            '/v1/contributions',
        ] as $path) {
            self::assertArrayHasKey($path, $doc['paths'], sprintf('contract lost %s', $path));
        }
    }

    /**
     * Every /v1 path advertised on the /developers teaser must exist in the
     * contract. The teaser uses the same `{id}` placeholder syntax as the
     * contract, so paths compare verbatim.
     */
    public function testDevelopersTeaserMatchesTheContract(): void
    {
        $doc = Yaml::parseFile(__DIR__.'/../../public/api/openapi.yaml');
        $catalog = Yaml::parseFile(__DIR__.'/../../translations/messages.en.yaml');

        $advertised = [];
        foreach ($catalog['developers'] as $key => $value) {
            if (preg_match('/^ep\d+_path$/', (string) $key) && str_starts_with((string) $value, '/v1/')) {
                $advertised[] = (string) $value;
            }
        }

        self::assertNotEmpty($advertised);
        foreach ($advertised as $path) {
            self::assertArrayHasKey($path, $doc['paths'], sprintf('/developers advertises %s but the contract lacks it', $path));
        }
    }
}
