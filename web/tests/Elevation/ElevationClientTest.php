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
}
