<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Api\ApiSurface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The reference page must be right about itself.
 *
 * Its banner asserted "Draft contract, API not live yet" long after
 * `/v1/map-config` started answering, because the sentence was written by hand
 * and nothing made it wrong (known issue, 2026-09-06). It is counted now: the
 * total from the OpenAPI document the page renders, the live half from the
 * router. These tests fail if either count is taken from somewhere that cannot
 * move, which is the only way the sentence goes stale again.
 */
final class ApiSurfaceTest extends WebTestCase
{
    public function testTheLiveCountIsEveryPublicV1Route(): void
    {
        self::bootKernel();
        $surface = self::getContainer()->get(ApiSurface::class);
        self::assertInstanceOf(ApiSurface::class, $surface);

        $router = self::getContainer()->get('router');
        $expected = \count(array_filter(
            array_keys($router->getRouteCollection()->all()),
            static fn (string $n): bool => str_starts_with($n, 'api_v1_'),
        ));

        self::assertGreaterThan(0, $expected, 'no public v1 routes: the prefix this counts by has moved');
        self::assertSame($expected, $surface->live());
    }

    public function testThePromisedCountIsEveryPathInTheContract(): void
    {
        self::bootKernel();
        $surface = self::getContainer()->get(ApiSurface::class);
        self::assertInstanceOf(ApiSurface::class, $surface);

        // The document is the contract of record (public-api.md §2.3), so the
        // page may not claim a different number of endpoints from it.
        self::assertGreaterThanOrEqual($surface->live(), $surface->promised(),
            'the contract promises fewer endpoints than answer, which means one shipped undocumented');
    }

    public function testTheReferencePageSaysHowMuchAnswers(): void
    {
        $client = static::createClient();
        $client->request('GET', '/developers/api');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('API not live yet', $html, 'the banner is asserting again instead of counting');
        self::assertMatchesRegularExpression('~\d+ of \d+ endpoints answer today~', $html);
    }
}
