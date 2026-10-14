<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Elevation;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads ground elevation via Valhalla `/height` (docs/specs/climb-elevation.md §2b-i).
 *
 * @see docs/specs/climb-elevation.md §2b-i
 *
 * @api
 */
final class ElevationClient
{
    /** Cap keeps one request from becoming an unbounded upstream read. */
    public const int MAX_POINTS = 600;

    /**
     * Share of samples that must be non-zero. Valhalla answers 0 with no tiles,
     * which would publish as a flat climb (docs/specs/climb-elevation.md §2d).
     */
    private const float MIN_NONZERO_SHARE = 0.5;

    /** A planned leg's profile is sampled this often along the line (docs/specs/route-planner.md section 4.2). */
    public const int PROFILE_RESAMPLE_M = 30;

    /** A leg's samples must be this far non-zero, or the leg has no heights (route-planner.md section 4.2). */
    private const float PROFILE_MIN_NONZERO_SHARE = 0.9;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $valhallaUrl,
        private readonly string $demSource,
        /**
         * Per-continent instance picker. Null keeps single-instance behaviour.
         */
        private readonly ?ElevationEndpoints $endpoints = null,
    ) {
    }

    /**
     * @param list<array{0: float, 1: float}> $coords [lat, lng] pairs
     *
     * @return array{elevations: non-empty-list<float>, source: string}|null
     *                                                                       null when elevation cannot be established (docs/specs/climb-elevation.md §2d).
     */
    public function heights(array $coords): ?array
    {
        // ELEVATION_URL is the master switch: unset disables profiles, never a guess (docs/specs/climb-elevation.md §2d).
        if ([] === $coords || \count($coords) > self::MAX_POINTS || '' === $this->valhallaUrl) {
            return null;
        }

        // Which continent's Valhalla holds tiles for this shape.
        $url = $this->endpoints?->forShape($coords) ?? $this->valhallaUrl;

        $shape = array_map(
            static fn (array $c): array => ['lat' => $c[0], 'lon' => $c[1]],
            $coords,
        );

        try {
            $res = $this->http->request('POST', rtrim($url, '/').'/height', [
                'json' => ['shape' => $shape],
                'timeout' => 8,
            ]);
            $data = $res->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('elevation lookup failed', ['error' => $e->getMessage()]);

            return null;
        }

        $raw = $data['height'] ?? null;
        if (!\is_array($raw) || \count($raw) !== \count($coords)) {
            $this->logger->warning('elevation reply did not match the request', [
                'wanted' => \count($coords),
                'got' => \is_array($raw) ? \count($raw) : null,
            ]);

            return null;
        }

        $elevations = [];
        $nonZero = 0;
        foreach ($raw as $v) {
            if (!is_numeric($v)) {
                return null;
            }
            $f = (float) $v;
            // Valhalla missing sample: null or a large negative sentinel.
            if ($f < -1000.0) {
                return null;
            }
            $elevations[] = $f;
            if (0.0 !== $f) {
                ++$nonZero;
            }
        }

        if ($nonZero / \count($elevations) < self::MIN_NONZERO_SHARE) {
            $this->logger->warning('elevation reply was mostly zeros - no tiles loaded for this area?', [
                'points' => \count($elevations),
                'nonZero' => $nonZero,
            ]);

            return null;
        }

        return ['elevations' => $elevations, 'source' => $this->demSource];
    }

    /**
     * A planned leg's profile: the line resampled every $resampleM metres by
     * the engine, each sample with its metres along, so a chart draws
     * distance rather than vertex index (docs/specs/route-planner.md section
     * 4.2). A line longer than MAX_POINTS vertices is thinned evenly first,
     * both ends kept. Null when there is no engine, it does not answer, or
     * fewer than PROFILE_MIN_NONZERO_SHARE of the samples are known: the leg
     * then keeps its line and loses its heights, never a flat profile.
     *
     * @param list<array{0: float, 1: float}> $coords [lat, lng] pairs, the routed line
     *
     * @return list<array{0: float, 1: float}>|null [metres along, elevation]
     */
    public function profile(array $coords, int $resampleM = self::PROFILE_RESAMPLE_M): ?array
    {
        if (\count($coords) < 2 || '' === $this->valhallaUrl) {
            return null;
        }
        $coords = self::thin($coords, self::MAX_POINTS);
        $url = $this->endpoints?->forShape($coords) ?? $this->valhallaUrl;
        $shape = array_map(
            static fn (array $c): array => ['lat' => $c[0], 'lon' => $c[1]],
            $coords,
        );
        try {
            $res = $this->http->request('POST', rtrim($url, '/').'/height', [
                'json' => ['shape' => $shape, 'range' => true, 'resample_distance' => $resampleM],
                'timeout' => 8,
            ]);
            $data = $res->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('leg profile lookup failed', ['error' => $e->getMessage()]);

            return null;
        }
        $raw = $data['range_height'] ?? null;
        if (!\is_array($raw) || \count($raw) < 2) {
            return null;
        }
        $out = [];
        $nonZero = 0;
        foreach ($raw as $pair) {
            if (!\is_array($pair) || !isset($pair[0]) || !is_numeric($pair[0])) {
                return null;
            }
            // A missing sample is null or a large negative sentinel: unknown, counted as zero.
            $h = isset($pair[1]) && is_numeric($pair[1]) && (float) $pair[1] >= -1000.0 ? (float) $pair[1] : 0.0;
            if (0.0 !== $h) {
                ++$nonZero;
            }
            $out[] = [(float) $pair[0], $h];
        }
        if ($nonZero / \count($out) < self::PROFILE_MIN_NONZERO_SHARE) {
            $this->logger->warning('leg profile was mostly zeros - no tiles loaded for this area?', [
                'points' => \count($out),
                'nonZero' => $nonZero,
            ]);

            return null;
        }

        return $out;
    }

    /**
     * An even sample of at most $max points, first and last kept.
     *
     * @param list<array{0: float, 1: float}> $coords
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function thin(array $coords, int $max): array
    {
        $n = \count($coords);
        if ($n <= $max) {
            return $coords;
        }
        $step = ($n - 1) / ($max - 1);
        $out = [];
        for ($i = 0; $i < $max; ++$i) {
            $out[] = $coords[(int) round($i * $step)];
        }

        return $out;
    }
}
