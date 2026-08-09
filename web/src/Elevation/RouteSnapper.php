<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Elevation;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Snaps the climb editor's two points to a road line, using OUR Valhalla.
 *
 * The editor used to ask the public OSRM demo server from the browser — a
 * service whose own policy forbids production reliance, and the one external
 * this project called in a rider's hot path with no agreement behind it
 * (external-systems audit, 2026-08-09). The same Valhalla that already answers
 * every /height request routes too, so the editor now asks us, exactly as it
 * does for elevation: one less CSP host, one less third party, and the road
 * network the line snaps to is the one this project operates.
 *
 * `bicycle` costing, deliberately — the old call used `driving`, which refuses
 * the cycleways and greenways some climbs actually ride (Hockai's whole line
 * is a RAVeL a car profile will not enter).
 *
 * Lives in the Elevation namespace because it shares Valhalla's configuration:
 * the master switch (ELEVATION_URL) and the per-continent endpoint selection.
 *
 * @api Consumed by RouteController (the /contribute/route proxy).
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
     *                                                                                    coordinates as [lng, lat] pairs (GeoJSON order, what the editor
     *                                                                                    draws); null when no route exists or the router is unreachable —
     *                                                                                    the editor keeps its straight line and says so, never a guess
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
                    // The editor wants the LINE; turn-by-turn prose is dead weight.
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
            // consecutive legs repeat the join point
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
