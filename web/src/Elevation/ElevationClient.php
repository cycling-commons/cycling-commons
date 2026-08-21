<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
}
