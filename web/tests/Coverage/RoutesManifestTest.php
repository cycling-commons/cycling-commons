<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Coverage;

use App\Coverage\RoutesManifest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The one-arm sibling of SurfaceManifestTest: same serving contract (manifest
 * follows the build, pin wins, every failure degrades to "no layer" rather
 * than a 500), one artifact instead of three.
 */
final class RoutesManifestTest extends TestCase
{
    private const MANIFEST = 'https://tiles.example/cc-maps/routes/manifest.json';

    private function manifest(MockHttpClient $http, string $pin = ''): RoutesManifest
    {
        return new RoutesManifest($http, new ArrayAdapter(), new NullLogger(), self::MANIFEST, $pin);
    }

    private static function body(array $tiles): MockResponse
    {
        return new MockResponse(json_encode(['version' => 1, 'stamp' => '20260813-0130', 'tiles' => $tiles]),
            ['response_headers' => ['content-type' => 'application/json']]);
    }

    public function testItServesThePublishedBuild(): void
    {
        $http = new MockHttpClient([self::body([
            'routes' => 'https://tiles.example/r/20260813-0130/routes.pmtiles',
        ])]);

        self::assertSame(
            'https://tiles.example/r/20260813-0130/routes.pmtiles',
            $this->manifest($http)->tilesUrl(),
        );
    }

    public function testAPinnedUrlWinsAndNeverTouchesTheBucket(): void
    {
        // The bisect hatch — it must work on an installation that has no
        // manifest at all, so it cannot depend on fetching one.
        $http = new MockHttpClient(static function (): MockResponse {
            self::fail('the manifest was fetched although the URL was pinned');
        });

        self::assertSame('https://pinned/routes.pmtiles',
            $this->manifest($http, 'https://pinned/routes.pmtiles')->tilesUrl());
    }

    public function testAnUnreachableManifestDegradesToNoLayerRatherThanAnError(): void
    {
        // A missing route layer is a smaller harm than a 500 on /map.
        $http = new MockHttpClient([new MockResponse('', ['http_code' => 503])]);
        self::assertNull($this->manifest($http)->tilesUrl());
    }

    public function testAManifestNamingNoTilesIsTreatedAsUnavailable(): void
    {
        $http = new MockHttpClient([self::body([])]);
        self::assertNull($this->manifest($http)->tilesUrl());
    }

    public function testAnUnsetManifestUrlIsSilentlyNoLayer(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            self::fail('an empty manifest URL must not be fetched');
        });
        $m = new RoutesManifest($http, new ArrayAdapter(), new NullLogger(), '');
        self::assertNull($m->tilesUrl());
    }
}
