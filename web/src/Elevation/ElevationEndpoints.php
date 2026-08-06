<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Elevation;

/**
 * Chooses which Valhalla answers a height lookup.
 *
 * Elevation is served by Valhalla's skadi, which reads `additional_data.elevation`
 * independently of the routing graph — so an instance answers `/height` for
 * exactly the `.hgt` tiles in its own directory and returns zeros everywhere
 * else (climb-elevation.md §2b-i). The project runs one Valhalla per continent,
 * each with its own tile set, so "which instance" is a question about where the
 * climb is, not about routing.
 *
 * Getting it wrong is not silent: an instance without tiles for the area answers
 * all-zeros, and ElevationClient::MIN_NONZERO_SHARE rejects that rather than
 * publishing a flat climb. A misrouted lookup therefore costs a missing profile,
 * never a wrong one.
 *
 * The boxes below are deliberately NOT the continents' true outlines. They are
 * the areas whose tiles are actually loaded, tested in a fixed priority order so
 * the overlaps resolve the way the tile sets do — Europe is first because the
 * EUROPE tile set already covers Sicily and southern Spain, which a true Africa
 * box would otherwise claim.
 *
 * @api Used by App\Elevation\ElevationClient.
 */
final class ElevationEndpoints
{
    /**
     * continent key => [lonMin, latMin, lonMax, latMax], FIRST MATCH WINS.
     *
     * `europe` mirrors tools/elevation/fetch-glo30.sh's EUROPE preset exactly;
     * widen both together or a climb lands on an instance with no tiles for it.
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
     * @param string $defaultUrl ELEVATION_URL — the instance used when no box
     *                           matches, and the only one used when $map is empty
     * @param string $map        ELEVATION_URLS, `key=url,key=url`. Unset means
     *                           single-instance behaviour, exactly as before.
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
            // An unknown key is dropped rather than trusted: it would silently
            // never match any box, and a typo'd `occeania` should not look
            // configured.
            if ('' !== $url && isset(self::BOXES[$key])) {
                $urls[$key] = $url;
            }
        }
        $this->urls = $urls;
    }

    /**
     * The instance that should answer for this shape.
     *
     * Decided from the FIRST point. A climb is a single road between a foot and
     * a summit, so it does not cross a continent; a shape that somehow did would
     * still be answered consistently by one instance rather than stitched from
     * two datasets, which is the safer of the two failures.
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
                // First match wins outright. A matched box with no configured
                // instance resolves to the default rather than sliding to the
                // next box: `europe` is deliberately absent from ELEVATION_URLS
                // because its tiles ARE the default instance's, and falling
                // through would hand Sicily to Africa.
                return $this->urls[$key] ?? $this->defaultUrl;
            }
        }

        return $this->defaultUrl;
    }
}
