<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Elevation;

use App\Elevation\ElevationClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The elevation lookup, and the ways it is allowed to fail.
 *
 * The zeros case is the reason this class exists: Valhalla answers 0 for every
 * point when it has no elevation tiles for an area rather than reporting an
 * error, and a sea-level profile is indistinguishable from a real one to
 * everything downstream. climb-elevation.md §2b-i.
 */
final class ElevationClientTest extends TestCase
{
    private function client(MockResponse $response): ElevationClient
    {
        return new ElevationClient(
            new MockHttpClient($response),
            new NullLogger(),
            'http://valhalla.test',
            'Copernicus DEM GLO-30',
        );
    }

    public function testItReturnsElevationsAndTheSourceThatAnsweredThem(): void
    {
        $client = $this->client(new MockResponse((string) json_encode(['height' => [133, 158, 190]])));

        $result = $client->heights([[50.4832, 5.7039], [50.4850, 5.7050], [50.4870, 5.7060]]);

        self::assertNotNull($result);
        self::assertSame([133.0, 158.0, 190.0], $result['elevations']);
        // Provenance travels with the numbers so the item can record which
        // dataset produced its gradients, rather than carrying an unattributed
        // figure (§4).
        self::assertSame('Copernicus DEM GLO-30', $result['source']);
    }

    public function testAnAllZeroReplyIsRejectedRatherThanPublishedAsAFlatClimb(): void
    {
        // What a Valhalla with no elevation tiles actually returns. It is not an
        // error response, which is precisely the danger.
        $client = $this->client(new MockResponse((string) json_encode(['height' => [0, 0, 0, 0]])));

        self::assertNull($client->heights([[50.1, 5.1], [50.2, 5.2], [50.3, 5.3], [50.4, 5.4]]));
    }

    public function testARouteThatMerelyTouchesSeaLevelStillWorks(): void
    {
        // The guard must not punish genuinely coastal climbs: only EXACT zeros
        // count, and a real climb is never mostly at exactly sea level.
        $client = $this->client(new MockResponse((string) json_encode(['height' => [0, 12, 48, 120]])));

        $result = $client->heights([[50.1, 5.1], [50.2, 5.2], [50.3, 5.3], [50.4, 5.4]]);

        self::assertNotNull($result);
        self::assertSame([0.0, 12.0, 48.0, 120.0], $result['elevations']);
    }

    public function testAReplyOfTheWrongLengthIsRejected(): void
    {
        // Silently zipping mismatched arrays would attach elevations to the
        // wrong coordinates — a wrong profile rather than no profile.
        $client = $this->client(new MockResponse((string) json_encode(['height' => [133, 158]])));

        self::assertNull($client->heights([[50.1, 5.1], [50.2, 5.2], [50.3, 5.3]]));
    }

    public function testAMissingSampleSentinelIsRejected(): void
    {
        $client = $this->client(new MockResponse((string) json_encode(['height' => [133, -32768, 190]])));

        self::assertNull($client->heights([[50.1, 5.1], [50.2, 5.2], [50.3, 5.3]]));
    }

    public function testAnUpstreamFailureResolvesToNoProfileRatherThanThrowing(): void
    {
        $client = $this->client(new MockResponse('', ['http_code' => 500]));

        self::assertNull($client->heights([[50.1, 5.1], [50.2, 5.2]]));
    }

    public function testWithoutAConfiguredServiceThereIsNoProfile(): void
    {
        // Unset ELEVATION_URL disables profiles rather than erroring: no
        // elevation, no gradients, never a guess (§2d).
        $client = new ElevationClient(new MockHttpClient(), new NullLogger(), '', 'x');

        self::assertNull($client->heights([[50.1, 5.1]]));
    }

    public function testTooManyPointsAreRefusedBeforeReachingUpstream(): void
    {
        $client = new ElevationClient(new MockHttpClient(), new NullLogger(), 'http://valhalla.test', 'x');
        $coords = array_fill(0, ElevationClient::MAX_POINTS + 1, [50.1, 5.1]);

        self::assertNull($client->heights($coords));
    }

    /** @var array<string, mixed> what the last mock request carried, decoded */
    private array $lastBody = [];

    private function recording(MockResponse $response): ElevationClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->lastBody = json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR);

            return $response;
        });

        return new ElevationClient($http, new NullLogger(), 'http://valhalla.test', 'Copernicus DEM GLO-30');
    }

    /* A planned leg's profile (docs/specs/route-planner.md section 4.2): the
       line resampled every 30 m by the engine, each sample with its metres
       along, so the chart draws distance, not vertex index. */

    public function testAProfileAsksForResampledHeightsWithTheDistanceAlong(): void
    {
        $client = $this->recording(new MockResponse((string) json_encode(['range_height' => [[0, 300], [30, 305], [60, 311]]])));

        $result = $client->profile([[50.1, 5.1], [50.2, 5.2]]);

        self::assertSame(30, $this->lastBody['resample_distance']);
        self::assertTrue($this->lastBody['range']);
        self::assertSame([['lat' => 50.1, 'lon' => 5.1], ['lat' => 50.2, 'lon' => 5.2]], $this->lastBody['shape']);
        self::assertSame([[0.0, 300.0], [30.0, 305.0], [60.0, 311.0]], $result);
    }

    public function testAProfileOfOnlyZerosIsNoProfile(): void
    {
        // Every sample exactly 0: the engine has no tiles here. The leg keeps
        // its line and loses its heights, never a flat profile.
        $zeros = array_map(static fn (int $i): array => [$i * 30, 0], range(0, 9));
        $client = $this->recording(new MockResponse((string) json_encode(['range_height' => $zeros])));

        self::assertNull($client->profile([[50.1, 5.1], [50.2, 5.2]]));
    }

    public function testAProfileAroundSeaLevelIsKeptThoughMostSamplesAreZero(): void
    {
        // Near Hoorn the land lies at sea level: real heights of 0 m, with a
        // dike or a dip between (owner 2026-10-17: "it should always show height data").
        $heights = [0, 0, 2, 0, -1, 0, 0, 4, 0, 0];
        $range = array_map(static fn (int $i, int $h): array => [$i * 30, $h], array_keys($heights), $heights);
        $client = $this->recording(new MockResponse((string) json_encode(['range_height' => $range])));

        self::assertSame([[0.0, 0.0], [30.0, 0.0], [60.0, 2.0], [90.0, 0.0], [120.0, -1.0], [150.0, 0.0], [180.0, 0.0], [210.0, 4.0], [240.0, 0.0], [270.0, 0.0]],
            $client->profile([[52.64, 5.05], [52.66, 5.09]]));
    }

    public function testAMissingSampleTakesTheHeightBetweenItsNeighboursNeverZero(): void
    {
        // A null or a sentinel is unknown, not 0 m: in the hills a 0 would draw a pit to sea level.
        $client = $this->recording(new MockResponse((string) json_encode(['range_height' => [[0, 250], [30, null], [60, 256], [90, -32768], [120, null], [150, 262], [180, null]]])));

        self::assertSame([[0.0, 250.0], [30.0, 253.0], [60.0, 256.0], [90.0, 258.0], [120.0, 260.0], [150.0, 262.0], [180.0, 262.0]],
            $client->profile([[50.49, 5.86], [50.47, 5.90]]));
    }

    public function testAProfileWithNoKnownSampleIsNoProfile(): void
    {
        $client = $this->recording(new MockResponse((string) json_encode(['range_height' => [[0, null], [30, -32768]]])));

        self::assertNull($client->profile([[50.1, 5.1], [50.2, 5.2]]));
    }

    public function testALongLineIsThinnedBeforeItIsSentAndKeepsItsEnds(): void
    {
        $client = $this->recording(new MockResponse((string) json_encode(['range_height' => [[0, 100], [30, 101]]])));
        $coords = [];
        for ($i = 0; $i < 1500; ++$i) {
            $coords[] = [50.0 + $i * 0.0001, 5.0];
        }

        self::assertNotNull($client->profile($coords));

        $shape = $this->lastBody['shape'];
        self::assertLessThanOrEqual(ElevationClient::MAX_POINTS, \count($shape));
        self::assertSame(['lat' => 50.0, 'lon' => 5.0], $shape[0]);
        self::assertEqualsWithDelta(50.1499, $shape[\count($shape) - 1]['lat'], 1e-9);
        self::assertSame(5.0, $shape[\count($shape) - 1]['lon']);
    }

    public function testAnEngineThatDoesNotAnswerGivesNoProfile(): void
    {
        $client = $this->recording(new MockResponse('', ['error' => 'connection refused']));

        self::assertNull($client->profile([[50.1, 5.1], [50.2, 5.2]]));
    }

    public function testNoEngineConfiguredGivesNoProfile(): void
    {
        $client = new ElevationClient(new MockHttpClient(), new NullLogger(), '', 'Copernicus DEM GLO-30');

        self::assertNull($client->profile([[50.1, 5.1], [50.2, 5.2]]));
    }
}
