<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Elevation;

use App\Catalog\BikeType;
use App\Elevation\PointSnapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A planner's map point on the road a bike would take there (docs/specs/route-planner.md
 * section 4.1, owner 2026-10-18: a checkpoint in a field routed up a farm's driveway).
 */
final class PointSnapperTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $lastBody = null;

    /**
     * @param list<array{0: float, 1: string, 2: bool, 3: float, 4: float}> $edges distance, use, bike access, lat, lon
     */
    private function snapper(array $edges): PointSnapper
    {
        $list = array_map(static fn (array $e): array => ['distance' => $e[0], 'correlated_lat' => $e[3], 'correlated_lon' => $e[4], 'edge' => ['access' => ['bicycle' => $e[2]], 'classification' => ['use' => $e[1], 'classification' => 'service_other']]], $edges);
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($list): MockResponse {
            $body = json_decode((string) ($options['body'] ?? ''), true);
            $this->lastBody = \is_array($body) ? $body : null;

            return new MockResponse((string) json_encode([['edges' => $list]]));
        });

        return new PointSnapper($http, new NullLogger(), 'https://engine.test');
    }

    public function testTheFarmsDrivewayIsPassedForTheRoadBeyondIt(): void
    {
        $at = $this->snapper([
            [18.0, 'driveway', true, 52.6601, 5.1101],
            [26.0, 'service_road', true, 52.6602, 5.1102],
            [64.0, 'road', true, 52.6605, 5.1110],
        ])->snap(52.66, 5.11, BikeType::Gravel);

        self::assertSame(['lat' => 52.6605, 'lng' => 5.111, 'distanceM' => 64], $at);
        self::assertSame(500, $this->lastBody['locations'][0]['radius'] ?? null);
        self::assertTrue($this->lastBody['verbose'] ?? false);
    }

    public function testARoadBikeKeepsOffTracksAndAWayBikesMayNotRideIsNoWay(): void
    {
        $edges = [[10.0, 'track', true, 52.1, 5.1], [20.0, 'road', false, 52.2, 5.2], [40.0, 'cycleway', true, 52.3, 5.3]];

        self::assertSame(['lat' => 52.3, 'lng' => 5.3, 'distanceM' => 40], $this->snapper($edges)->snap(52.0, 5.0, BikeType::Road));
        self::assertSame(['lat' => 52.1, 'lng' => 5.1, 'distanceM' => 10], $this->snapper($edges)->snap(52.0, 5.0, BikeType::Gravel), 'a gravel bike takes the track');
    }

    public function testNothingRideableWithinTheRadiusLeavesThePoint(): void
    {
        self::assertNull($this->snapper([[30.0, 'driveway', true, 52.1, 5.1], [600.0, 'road', true, 52.2, 5.2]])->snap(52.0, 5.0, BikeType::Road));
        self::assertNull((new PointSnapper(new MockHttpClient(), new NullLogger(), ''))->snap(52.0, 5.0, BikeType::Road), 'no engine, no snap');
    }
}
