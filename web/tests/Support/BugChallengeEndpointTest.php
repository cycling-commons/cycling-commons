<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * The floating bug panel fetches its single-use values instead of finding them
 * in the page (docs/specs/page-caching.md §3.1).
 *
 * Three separate reasons, and the tests below are ordered by how much each one
 * hurt:
 *
 * * **A challenge is single use.** The bug button renders on every page, so a
 *   page held in a shared cache handed the same challenge to every reader.
 *   The first person to file a report spent it and everybody else was rejected
 *   after solving a puzzle that was already used. This is what blocked caching
 *   the public pages at all.
 * * **A retry could never work.** After a rejected send the panel cleared its
 *   solution but kept the spent challenge, so the second attempt failed for
 *   the same reason as the first. That bug predates any caching.
 * * **Almost every challenge was wasted.** One was minted per page view and
 *   spent only by the rare visitor who actually files something.
 */
final class BugChallengeEndpointTest extends WebTestCase
{
    private function client(): KernelBrowser
    {
        return static::createClient();
    }

    /** @return array<string, mixed> */
    private function fetch(KernelBrowser $client): array
    {
        $client->request('GET', '/form-challenge', server: ['HTTP_ACCEPT' => 'application/json']);

        return (array) json_decode($client->getResponse()->getContent() ?: '', true);
    }

    public function testItHandsOutEverythingThePanelNeeds(): void
    {
        $out = $this->fetch($this->client());

        self::assertResponseIsSuccessful();
        self::assertTrue($out['ok'] ?? false);
        foreach (['challenge', 'difficulty', 'stamp', 'token'] as $key) {
            self::assertArrayHasKey($key, $out);
            self::assertNotSame('', $out[$key], "$key came back empty");
        }
    }

    /**
     * The whole point. Two callers must never receive the same challenge,
     * because the second one to file a report would be refused.
     */
    public function testEveryCallerGetsTheirOwnChallenge(): void
    {
        $client = $this->client();
        $first = $this->fetch($client);
        $second = $this->fetch($client);

        self::assertNotSame($first['challenge'], $second['challenge']);
    }

    /**
     * Belt and braces for the above: a cache anywhere in the chain holding this
     * response would recreate exactly the bug the endpoint exists to remove.
     */
    public function testTheAnswerMayNeverBeStored(): void
    {
        $this->fetch($this->client());

        $cacheControl = static::getClient()->getResponse()->headers->get('Cache-Control') ?? '';
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
    }

    /**
     * Public, unauthenticated, and it mints signed tokens, so it is bounded.
     * The limiter is swapped before the first request: the container's test
     * pool is reset between requests.
     */
    public function testItIsBounded(): void
    {
        $client = $this->client();
        $budget = new RateLimiterFactory(
            ['id' => 'pow_challenge_test', 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
        static::getContainer()->set('limiter.pow_challenge', $budget);
        self::assertTrue($budget->create('ip-127.0.0.1')->consume()->isAccepted());

        $client->request('GET', '/form-challenge', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(429);
    }

    /**
     * The regression guard for the caching work: if a single-use value creeps
     * back into the markup that renders on every page, the pages stop being
     * safe to cache and nothing else in this file would notice.
     */
    public function testThePageItselfCarriesNothingSingleUse(): void
    {
        $client = $this->client();
        $client->request('GET', '/');
        $html = $client->getResponse()->getContent() ?: '';

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-challenge-url=', $html, 'the panel must know where to ask');
        foreach (['data-pow-challenge=', 'data-stamp=', 'data-token='] as $gone) {
            self::assertStringNotContainsString($gone, $html, "$gone is single-use and cannot sit in a cacheable page");
        }
    }
}
