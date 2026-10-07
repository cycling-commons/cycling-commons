<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Onboarding;

use App\Elevation\ElevationEndpoints;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One raw /height question per point, to the instance ElevationEndpoints picks.
 *
 * Unlike ElevationClient it keeps 0 and null apart from an error, because
 * the onboarding check counts them (docs/specs/climb-elevation.md §2d), and
 * a down instance must not read as missing tiles.
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

    /** @throws ElevationUnreachable on a timeout, a transport error or a non-2xx answer */
    public function height(string $endpoint, float $lat, float $lon): ?float
    {
        try {
            $response = $this->http->request('POST', rtrim($endpoint, '/').'/height', [
                'json' => ['shape' => [['lat' => $lat, 'lon' => $lon]]],
                'timeout' => 8,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new ElevationUnreachable($endpoint, sprintf('HTTP %d', $status));
            }
            $data = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            throw new ElevationUnreachable($endpoint, $e->getMessage(), $e);
        } catch (DecodingExceptionInterface) {
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
