<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Elevation;

use App\Elevation\CoveredSpans;
use App\Elevation\ElevationEndpoints;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Finding the roofed stretches of a climb.
 *
 * Where a road is covered a Digital Surface Model reads the mountain on top of
 * it, so these spans are the difference between Grimsel measuring ~11% and
 * measuring 15% (climb-elevation.md §2a).
 */
final class CoveredSpansTest extends TestCase
{
    /** A short straight shape, encoded as Valhalla returns it (polyline6). */
    private const string SHAPE = '_c`|`Aoa~qOoiAoiAoiAoiAoiAoiAoiAoiA';

    private function spans(MockResponse $response): CoveredSpans
    {
        return new CoveredSpans(
            new MockHttpClient($response),
            new NullLogger(),
            new ElevationEndpoints('http://valhalla.test'),
        );
    }

    /** @return list<array{0: float, 1: float}> */
    private function line(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; ++$i) {
            $out[] = [46.5 + $i * 0.001, 8.3];
        }

        return $out;
    }

    public function testATunnelEdgeBecomesAFractionalSpan(): void
    {
        $spans = $this->spans(new MockResponse((string) json_encode([
            'shape' => self::SHAPE,
            'edges' => [
                ['tunnel' => false, 'begin_shape_index' => 0, 'end_shape_index' => 2],
                ['tunnel' => true, 'begin_shape_index' => 2, 'end_shape_index' => 4],
            ],
        ])));

        $got = $spans->forShape($this->line(10));

        self::assertCount(1, $got);
        // Fractions, not metres: map-matching returns the carriageway, whose
        // length is not the length of the shape we sent.
        self::assertGreaterThan(0.0, $got[0][0]);
        self::assertLessThanOrEqual(1.0, $got[0][1]);
        self::assertGreaterThan($got[0][0], $got[0][1]);
    }

    public function testConsecutiveTunnelEdgesMergeIntoOneGallery(): void
    {
        // One bore arrives as several edges; left unmerged it reads as several
        // galleries and over-fragments the exclusion.
        $spans = $this->spans(new MockResponse((string) json_encode([
            'shape' => self::SHAPE,
            'edges' => [
                ['tunnel' => true, 'begin_shape_index' => 1, 'end_shape_index' => 3],
                ['tunnel' => true, 'begin_shape_index' => 3, 'end_shape_index' => 5],
            ],
        ])));

        self::assertCount(1, $spans->forShape($this->line(10)));
    }

    public function testAnOpenRoadHasNoCoveredSpans(): void
    {
        $spans = $this->spans(new MockResponse((string) json_encode([
            'shape' => self::SHAPE,
            'edges' => [['tunnel' => false, 'begin_shape_index' => 0, 'end_shape_index' => 4]],
        ])));

        self::assertSame([], $spans->forShape($this->line(10)));
    }

    public function testALookupFailureCostsAccuracyAndNeverTheProfile(): void
    {
        // A routing outage must degrade to the pre-2026-08-07 answer, not to a
        // missing climb profile — the elevation read has already succeeded by
        // the time this is asked.
        $spans = $this->spans(new MockResponse('nope', ['http_code' => 500]));

        self::assertSame([], $spans->forShape($this->line(10)));
    }

    public function testAnUnconfiguredInstanceIsNotCalled(): void
    {
        $spans = new CoveredSpans(
            new MockHttpClient(new MockResponse('{}')),
            new NullLogger(),
            new ElevationEndpoints(''),
        );

        self::assertSame([], $spans->forShape($this->line(10)));
    }
}
