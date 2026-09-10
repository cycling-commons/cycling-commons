<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Elevation;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Snaps two points to a road line via our Valhalla (`bicycle` costing)
 * (docs/specs/climb-elevation.md §3e). Null when unset or unreachable — never a guess.
 *
 * @api
 */
final class RouteSnapper
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $valhallaUrl,
        private readonly ?ElevationEndpoints $endpoints = null,
    ) {
    }

    /**
     * @param array{0: float, 1: float} $a [lat, lng]
     * @param array{0: float, 1: float} $b [lat, lng]
     *
     * @return array{coordinates: list<array{0: float, 1: float}>, distanceM: float}|null
     *                                                                                    [lng, lat] GeoJSON; null when unset or unreachable
     */
    public function snap(array $a, array $b): ?array
    {
        if ('' === $this->valhallaUrl) {
            return null;   // same master switch as elevation: unset = no routing
        }

        $url = $this->endpoints?->forShape([$a, $b]) ?? $this->valhallaUrl;

        try {
            $res = $this->http->request('POST', rtrim($url, '/').'/route', [
                'json' => [
                    'locations' => [
                        ['lat' => $a[0], 'lon' => $a[1]],
                        ['lat' => $b[0], 'lon' => $b[1]],
                    ],
                    'costing' => 'bicycle',
                    // The editor wants the line; turn-by-turn prose is dead weight.
                    'directions_type' => 'none',
                ],
                'timeout' => 8,
            ]);
            $data = $res->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('route snap failed', ['error' => $e->getMessage()]);

            return null;
        }

        $legs = $data['trip']['legs'] ?? null;
        if (!\is_array($legs) || [] === $legs) {
            return null;
        }

        $coordinates = [];
        $distanceKm = 0.0;
        foreach ($legs as $leg) {
            $shape = $leg['shape'] ?? null;
            if (!\is_string($shape) || '' === $shape) {
                return null;
            }
            $pts = self::decodePolyline6($shape);
            if (\count($pts) < 2) {
                return null;
            }
            // Consecutive legs repeat the join point.
            if ([] !== $coordinates) {
                array_shift($pts);
            }
            $coordinates = array_merge($coordinates, $pts);
            $distanceKm += (float) ($leg['summary']['length'] ?? 0.0);
        }

        return ['coordinates' => $coordinates, 'distanceM' => round($distanceKm * 1000.0, 1)];
    }

    /**
     * Valhalla's encoded shape: Google polyline algorithm at 1e-6 precision.
     *
     * @return list<array{0: float, 1: float}> [lng, lat] pairs
     */
    private static function decodePolyline6(string $encoded): array
    {
        $points = [];
        $index = 0;
        $len = \strlen($encoded);
        $lat = 0;
        $lng = 0;
        while ($index < $len) {
            foreach (['lat', 'lng'] as $which) {
                $result = 0;
                $shift = 0;
                do {
                    if ($index >= $len) {
                        return $points;   // truncated shape: keep what decoded cleanly
                    }
                    $byte = \ord($encoded[$index++]) - 63;
                    $result |= ($byte & 0x1F) << $shift;
                    $shift += 5;
                } while ($byte >= 0x20);
                $delta = ($result & 1) ? ~($result >> 1) : ($result >> 1);
                if ('lat' === $which) {
                    $lat += $delta;
                } else {
                    $lng += $delta;
                }
            }
            $points[] = [(float) $lng / 1e6, (float) $lat / 1e6];
        }

        return $points;
    }
}
