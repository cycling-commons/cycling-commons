<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media\Commons;

use App\Media\Commons\CommonsApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A multi-day race edition on Wikidata often carries only a start time
 * (P580), no point in time (P585): the 2006 Eneco Tour, which started in Den
 * Helder, is one. The town card then said "2 editions · last" with no year
 * (owner 2026-09-28). The year comes from whichever of the two it has.
 */
final class CyclingEventsYearTest extends TestCase
{
    public function testAnEditionWithOnlyAStartTimeStillHasItsYear(): void
    {
        $query = '';
        $http = new MockHttpClient(static function (string $method, string $url) use (&$query): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $params);
            $query = \is_string($params['query'] ?? null) ? $params['query'] : '';

            return new MockResponse(json_encode(['results' => ['bindings' => [
                ['grp' => ['value' => 'http://www.wikidata.org/entity/Q1282925'], 'rel' => ['value' => 'http://www.wikidata.org/prop/direct/P1427'],
                    'stage' => ['value' => 'false'], 'n' => ['value' => '2'], 'last' => ['value' => '2006-08-16T00:00:00Z']],
            ]]], \JSON_THROW_ON_ERROR));
        });

        $events = (new CommonsApi($http))->cyclingEventsAt('Q9911');

        self::assertMatchesRegularExpression('/OPTIONAL \{ \?item wdt:P585 \?pointInTime \}\s*OPTIONAL \{ \?item wdt:P580 \?startTime \}\s*BIND\(COALESCE\(\?pointInTime, \?startTime\) AS \?when\)/', $query, 'point in time, else start time');
        self::assertSame(2006, $events[0]['last']);
    }
}
