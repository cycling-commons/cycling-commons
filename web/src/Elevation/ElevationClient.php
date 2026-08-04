<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Elevation;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads ground elevation for a list of coordinates.
 *
 * Server-side on purpose. The climb editor used to call a public elevation API
 * straight from the browser, which put the DEM behind a CORS allowlist, a
 * third party's rate limit, and no ability to choose the dataset. Elevation is
 * the input to every published gradient, so it belongs where it can be
 * configured, cached and swapped.
 *
 * Talks to Valhalla's `/height`, which is already in the stack for routing and
 * reads its rasters from `additional_data.elevation` at request time.
 *
 * @see docs/specs/climb-elevation.md §2b-i
 *
 * @api Used by App\Controller\ElevationController.
 */
final class ElevationClient
{
    /**
     * Valhalla accepts far more, but a climb is sampled at ~100 points and a
     * cap keeps one request from turning into an unbounded upstream read.
     */
    public const int MAX_POINTS = 600;

    /**
     * Share of samples that must be non-zero for a reply to be believed.
     *
     * Valhalla does not fail when it has no elevation tiles for an area: it
     * answers 0 for every point, which is a valid-looking sea-level profile and
     * would be published as a flat climb. Nothing downstream can detect that,
     * so it has to be caught here.
     *
     * A real climb is never mostly at exactly sea level, while a tile-less
     * region is entirely zero, so the two are far apart and the threshold does
     * not need to be delicate. Genuinely coastal routes keep working because
     * only *exact* zeros count.
     */
    private const float MIN_NONZERO_SHARE = 0.5;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $valhallaUrl,
        private readonly string $demSource,
    ) {
    }

    /**
     * @param list<array{0: float, 1: float}> $coords [lat, lng] pairs
     *
     * @return array{elevations: list<float>, source: string}|null null when
     *                                                             elevation cannot be established -- the caller must show no profile
     *                                                             rather than a guessed one (climb-elevation.md §2d)
     */
    public function heights(array $coords): ?array
    {
        if ([] === $coords || \count($coords) > self::MAX_POINTS || '' === $this->valhallaUrl) {
            return null;
        }

        $shape = array_map(
            static fn (array $c): array => ['lat' => $c[0], 'lon' => $c[1]],
            $coords,
        );

        try {
            $res = $this->http->request('POST', rtrim($this->valhallaUrl, '/').'/height', [
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
            // Valhalla reports a missing sample as null or a large negative
            // sentinel; either way it is not ground we can publish.
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
