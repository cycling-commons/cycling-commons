<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Elevation;

use App\Catalog\BikeType;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A planner's map point on the road a bike would take there (docs/specs/route-planner.md
 * section 4.1, owner 2026-10-18: a checkpoint dropped in a field routed up a farm's
 * driveway and back). Valhalla's /locate lists the edges round the point; the nearest
 * one the bike may ride that is not a private access piece (a driveway, a parking aisle,
 * a service road, a drive-through, an alley, emergency access) wins, and for a road bike
 * not a track or a path either. Null when the engine is off, does not answer, or nothing
 * fits within RADIUS_M (500 m, owner 2026-10-18): the point then stays where the rider put it. Nothing is kept.
 *
 * @api
 */
final class PointSnapper
{
    /** How far the engine looks round the point, metres. */
    public const int RADIUS_M = 500;

    /** Edge uses that are someone's way in, not a way through. */
    private const array PRIVATE_USES = ['driveway', 'parking_aisle', 'drive_through', 'emergency_access', 'alley', 'service_road'];

    /** Edge uses a road bike's line keeps off, as its costing does. */
    private const array ROAD_BIKE_OFF = ['track', 'path', 'footway', 'mountain_bike', 'bridleway', 'pedestrian', 'steps'];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $valhallaUrl,
        private readonly ?ElevationEndpoints $endpoints = null,
    ) {
    }

    /**
     * @return array{lat: float, lng: float, distanceM: int}|null
     */
    public function snap(float $lat, float $lng, BikeType $bike): ?array
    {
        if ('' === $this->valhallaUrl) {
            return null;
        }
        $url = $this->endpoints?->forPoint($lat, $lng) ?? $this->valhallaUrl;
        try {
            $data = $this->http->request('POST', rtrim($url, '/').'/locate', [
                'json' => [
                    'locations' => [['lat' => $lat, 'lon' => $lng, 'radius' => self::RADIUS_M]],
                    'costing' => 'bicycle',
                    'verbose' => true,
                ],
                'timeout' => 5,
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('point snap failed', ['error' => $e->getMessage()]);

            return null;
        }
        $edges = \is_array($data[0] ?? null) ? ($data[0]['edges'] ?? null) : null;
        if (!\is_array($edges)) {
            return null;
        }
        $best = null;
        foreach ($edges as $candidate) {
            if (!\is_array($candidate) || !is_numeric($candidate['distance'] ?? null) || !is_numeric($candidate['correlated_lat'] ?? null) || !is_numeric($candidate['correlated_lon'] ?? null)) {
                continue;
            }
            $edge = $candidate['edge'] ?? null;
            if (!\is_array($edge) || !self::takes($edge, $bike)) {
                continue;
            }
            $d = (float) $candidate['distance'];
            if ($d > self::RADIUS_M || (null !== $best && $d >= $best['distanceM'])) {
                continue;
            }
            $best = ['lat' => (float) $candidate['correlated_lat'], 'lng' => (float) $candidate['correlated_lon'], 'distanceM' => (int) round($d)];
        }

        return $best;
    }

    /**
     * @param array<mixed> $edge
     */
    private static function takes(array $edge, BikeType $bike): bool
    {
        $access = $edge['access'] ?? null;
        if (!\is_array($access) || true !== ($access['bicycle'] ?? null)) {
            return false;
        }
        $classification = $edge['classification'] ?? null;
        $use = \is_array($classification) ? ($classification['use'] ?? null) : null;
        if (!\is_string($use) || \in_array($use, self::PRIVATE_USES, true)) {
            return false;
        }

        return BikeType::Road !== $bike || !\in_array($use, self::ROAD_BIKE_OFF, true);
    }
}
