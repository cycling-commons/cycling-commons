<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Elevation;

/**
 * Chooses which Valhalla answers a height lookup (docs/specs/climb-elevation.md §2b-ii).
 * First match wins; a missing instance falls back to the default, never the next box.
 *
 * @api
 */
final class ElevationEndpoints
{
    /**
     * continent key => [lonMin, latMin, lonMax, latMax], FIRST MATCH WINS.
     *
     * @var array<string, array{float, float, float, float}>
     */
    private const array BOXES = [
        'europe' => [-11.0, 35.0, 32.0, 72.0],
        'north-america' => [-170.0, 15.0, -50.0, 75.0],
        'south-america' => [-82.0, -56.0, -34.0, 13.0],
        'africa' => [-20.0, -35.0, 52.0, 38.0],
        'asia' => [32.0, 0.0, 180.0, 82.0],
        'oceania' => [110.0, -50.0, 180.0, 0.0],
    ];

    /** @var array<string, string> continent key => base URL */
    private readonly array $urls;

    /**
     * @param string $defaultUrl ELEVATION_URL fallback
     * @param string $map        ELEVATION_URLS as `key=url,key=url`; empty = single instance
     */
    public function __construct(
        private readonly string $defaultUrl,
        string $map = '',
    ) {
        $urls = [];
        foreach (explode(',', $map) as $pair) {
            $pair = trim($pair);
            if ('' === $pair) {
                continue;
            }
            $parts = explode('=', $pair, 2);
            if (2 !== \count($parts)) {
                continue;
            }
            [$key, $url] = [trim($parts[0]), trim($parts[1])];
            // Unknown key is dropped rather than trusted.
            if ('' !== $url && isset(self::BOXES[$key])) {
                $urls[$key] = $url;
            }
        }
        $this->urls = $urls;
    }

    /**
     * Instance for this shape, from the first point.
     *
     * @param list<array{0: float, 1: float}> $coords [lat, lng] pairs
     */
    public function forShape(array $coords): string
    {
        if ([] === $coords || [] === $this->urls) {
            return $this->defaultUrl;
        }

        return $this->forPoint($coords[0][0], $coords[0][1]);
    }

    /** The instance whose tile set covers this point, or the default. */
    public function forPoint(float $lat, float $lon): string
    {
        foreach (self::BOXES as $key => [$lonMin, $latMin, $lonMax, $latMax]) {
            if ($lon >= $lonMin && $lon <= $lonMax && $lat >= $latMin && $lat <= $latMax) {
                // First match wins; a matched box with no URL uses the default, never the next box.
                return $this->urls[$key] ?? $this->defaultUrl;
            }
        }

        return $this->defaultUrl;
    }
}
