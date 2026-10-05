<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Onboarding;

use App\Elevation\ElevationEndpoints;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One raw /height question per point, to the instance ElevationEndpoints picks.
 *
 * Unlike ElevationClient it keeps 0 and null apart from an error, because
 * the onboarding check counts them (docs/specs/climb-elevation.md §2d).
 *
 * @api
 */
final class ElevationProbe
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ElevationEndpoints $endpoints,
    ) {
    }

    public function endpointFor(float $lat, float $lon): string
    {
        return $this->endpoints->forPoint($lat, $lon);
    }

    public function height(string $endpoint, float $lat, float $lon): ?float
    {
        try {
            $data = $this->http->request('POST', rtrim($endpoint, '/').'/height', [
                'json' => ['shape' => [['lat' => $lat, 'lon' => $lon]]],
                'timeout' => 8,
            ])->toArray(false);
        } catch (\Throwable) {
            return null;
        }
        $heights = $data['height'] ?? null;
        $value = \is_array($heights) ? ($heights[0] ?? null) : null;
        if (!is_numeric($value)) {
            return null;
        }
        $h = (float) $value;

        return $h < -1000.0 ? null : $h;
    }
}
