<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Elevation;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

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

    /** The points per side of the grid that asks whether a one-degree square has heights loaded (tilesLoaded()). */
    private const int PROBE_GRID = 5;

    /** The most one-degree squares one all-zero leg is checked in: a planned leg spans one or two. */
    private const int MAX_PROBE_SQUARES = 4;

    /** A planned leg's profile is sampled this often along the line (docs/specs/route-planner.md section 4.2). */
    public const int PROFILE_RESAMPLE_M = 30;

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
     * both ends kept. Null when there is no engine, it does not answer, no
     * sample is known, or every sample is exactly 0, which is how the engine
     * answers without tiles: the leg then keeps its line and loses its
     * heights, never a flat profile. Land at sea level keeps its heights,
     * zeros and all (owner 2026-10-17: Hoorn lost its profile), and a leg
     * all at 0 m keeps them too when the engine has heights in its squares
     * (tilesLoaded()). A missing
     * sample takes the height between its known neighbours, never 0 m.
     *
     * @param list<array{0: float, 1: float}> $coords [lat, lng] pairs, the routed line
     *
     * @return list<array{0: float, 1: float}>|null [metres along, elevation]
     */
    public function profile(array $coords, int $resampleM = self::PROFILE_RESAMPLE_M): ?array
    {
        $res = $this->profileRequest($coords, $resampleM);

        return null === $res ? null : $this->profileResult($res, $coords);
    }

    /**
     * Many legs at once (route-planner.md section 8, owner 2026-10-18): every request goes out before the first
     * answer is read. One profile per line, in order, each as profile() answers it.
     *
     * @param list<list<array{0: float, 1: float}>> $lines [lat, lng] pairs per leg
     *
     * @return list<list<array{0: float, 1: float}>|null>
     */
    public function profileMany(array $lines, int $resampleM = self::PROFILE_RESAMPLE_M): array
    {
        $pending = array_map(fn (array $coords): ?ResponseInterface => $this->profileRequest($coords, $resampleM), $lines);

        return array_map(fn (?ResponseInterface $res, array $coords): ?array => null === $res ? null : $this->profileResult($res, $coords), $pending, $lines);
    }

    /**
     * @param list<array{0: float, 1: float}> $coords
     */
    private function profileRequest(array $coords, int $resampleM): ?ResponseInterface
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
            return $this->http->request('POST', rtrim($url, '/').'/height', [
                'json' => ['shape' => $shape, 'range' => true, 'resample_distance' => $resampleM],
                'timeout' => 8,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('leg profile lookup failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param list<array{0: float, 1: float}> $coords the line asked, [lat, lng]
     *
     * @return list<array{0: float, 1: float}>|null
     */
    private function profileResult(ResponseInterface $res, array $coords): ?array
    {
        try {
            $data = $res->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('leg profile lookup failed', ['error' => $e->getMessage()]);

            return null;
        }
        $raw = $data['range_height'] ?? null;
        if (!\is_array($raw) || \count($raw) < 2) {
            return null;
        }
        $along = [];
        $heights = [];
        foreach ($raw as $pair) {
            if (!\is_array($pair) || !isset($pair[0]) || !is_numeric($pair[0])) {
                return null;
            }
            $along[] = (float) $pair[0];
            // A missing sample is null or a large negative sentinel: unknown.
            $heights[] = isset($pair[1]) && is_numeric($pair[1]) && (float) $pair[1] >= -1000.0 ? (float) $pair[1] : null;
        }
        $known = array_filter($heights, static fn (?float $h): bool => null !== $h);
        // Every sample 0 is how the engine answers without tiles, and how a leg along the Markermeer really lies
        // (owner 2026-10-18: the last leg into Hoorn lost its heights). The zeros are kept when the engine has heights
        // elsewhere in the same squares: its tiles are loaded there, so 0 m is the ground.
        if ([] !== $known && [] === array_filter($known, static fn (float $h): bool => 0.0 !== $h) && $this->tilesLoaded($coords)) {
            return array_map(static fn (float $d): array => [$d, 0.0], $along);
        }
        if ([] === $known || [] === array_filter($known, static fn (float $h): bool => 0.0 !== $h)) {
            $this->logger->warning('leg profile had no height but 0 - no tiles loaded for this area?', [
                'points' => \count($heights),
                'known' => \count($known),
            ]);

            return null;
        }
        $filled = self::fillUnknown($along, $heights);

        return array_map(static fn (float $d, float $h): array => [$d, $h], $along, $filled);
    }

    /**
     * Whether the engine has heights loaded in every one-degree square the line crosses (the squares its height tiles
     * cover): a grid of PROBE_GRID x PROBE_GRID points over each square, asked in one request, and a square counts
     * as loaded when one of them is not 0. More than MAX_PROBE_SQUARES squares, or no answer, is not loaded.
     *
     * @param list<array{0: float, 1: float}> $coords [lat, lng]
     */
    private function tilesLoaded(array $coords): bool
    {
        $squares = [];
        foreach ($coords as [$lat, $lng]) {
            $squares[(int) floor($lat).'/'.(int) floor($lng)] = [(int) floor($lat), (int) floor($lng)];
        }
        if ([] === $squares || \count($squares) > self::MAX_PROBE_SQUARES) {
            return false;
        }
        $shape = [];
        foreach ($squares as [$lat, $lng]) {
            for ($i = 0; $i < self::PROBE_GRID; ++$i) {
                for ($j = 0; $j < self::PROBE_GRID; ++$j) {
                    $shape[] = ['lat' => $lat + ($i + 0.5) / self::PROBE_GRID, 'lon' => $lng + ($j + 0.5) / self::PROBE_GRID];
                }
            }
        }
        $url = $this->endpoints?->forShape($coords) ?? $this->valhallaUrl;
        try {
            $data = $this->http->request('POST', rtrim($url, '/').'/height', ['json' => ['shape' => $shape], 'timeout' => 8])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('height tile probe failed', ['error' => $e->getMessage()]);

            return false;
        }
        $heights = $data['height'] ?? null;
        if (!\is_array($heights) || \count($heights) !== \count($shape)) {
            return false;
        }
        $per = self::PROBE_GRID * self::PROBE_GRID;
        foreach (array_chunk($heights, $per) as $square) {
            if ([] === array_filter($square, static fn (mixed $h): bool => is_numeric($h) && 0.0 !== (float) $h && (float) $h >= -1000.0)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Unknown heights from their known neighbours: between two, on the straight
     * line by distance along; before the first or after the last, that one.
     *
     * @param list<float>      $along
     * @param list<float|null> $heights at least one known
     *
     * @return list<float>
     */
    private static function fillUnknown(array $along, array $heights): array
    {
        $out = $heights;
        $prev = null;
        foreach ($heights as $i => $h) {
            if (null !== $h) {
                $prev = $i;
                continue;
            }
            $next = null;
            for ($j = $i + 1, $n = \count($heights); $j < $n; ++$j) {
                if (null !== $heights[$j]) {
                    $next = $j;
                    break;
                }
            }
            if (null !== $prev && null !== $next) {
                $span = $along[$next] - $along[$prev];
                $t = $span > 0.0 ? ($along[$i] - $along[$prev]) / $span : 0.0;
                $out[$i] = round((float) $heights[$prev] + ((float) $heights[$next] - (float) $heights[$prev]) * $t, 1);
            } else {
                $out[$i] = (float) $heights[$prev ?? $next];
            }
        }

        return array_map(static fn (?float $h): float => (float) $h, $out);
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
