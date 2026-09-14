<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

use App\Elevation\ElevationEndpoints;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The nearest way a bike may ride, from our Valhalla's /locate.
 *
 * The same ways the P letter's coverage rule counts
 * (pipeline/contract/coverage-contract.json `nearWay`), read from what
 * Valhalla exposes instead of from OSM tags: a road or a cycleway, and a path,
 * footway, track or bridleway only when it is marked for bikes (a dedicated or
 * separated cycle lane). An edge bikes may not use never counts, and neither
 * does any path with a hiking grade (`sac_scale`): bicycle costing snaps the
 * Matterhorn's summit to a climbers' path 4 m away.
 *
 * @see docs/specs/scenic-views.md
 *
 * @api
 */
final class ValhallaBikeWayLocator implements BikeWayLocator
{
    /** Valhalla edge uses that are a way to ride as they stand. */
    private const array RIDE_USES = ['road', 'cycleway', 'living_street', 'service_road', 'driveway', 'alley', 'parking_aisle'];

    /** Edge uses that count only with a cycle lane marked on them. */
    private const array MARKED_USES = ['path', 'footway', 'track', 'bridleway', 'mountain_bike', 'pedestrian'];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $valhallaUrl,
        private readonly ?ElevationEndpoints $endpoints = null,
    ) {
    }

    #[\Override]
    public function nearest(float $lat, float $lng): BikeWayReading
    {
        if ('' === $this->valhallaUrl) {
            return BikeWayReading::unknown();
        }
        $url = $this->endpoints?->forPoint($lat, $lng) ?? $this->valhallaUrl;

        try {
            $data = $this->http->request('POST', rtrim($url, '/').'/locate', [
                'json' => [
                    'locations' => [['lat' => $lat, 'lon' => $lng, 'radius' => BikeWayReading::SCENIC_WITHIN_M]],
                    'costing' => 'bicycle',
                    'verbose' => true,
                ],
                'timeout' => 6,
            ])->toArray();
        } catch (\Throwable $e) {
            $this->logger->warning('bike way locate failed', ['error' => $e->getMessage()]);

            return BikeWayReading::unknown();
        }

        $edges = $data[0]['edges'] ?? null;
        if (!\is_array($edges)) {
            return new BikeWayReading(true, null);
        }

        $nearest = null;
        foreach ($edges as $candidate) {
            if (!\is_array($candidate) || !is_numeric($candidate['distance'] ?? null) || !\is_array($candidate['edge'] ?? null)) {
                continue;
            }
            if (!self::rideable($candidate['edge'])) {
                continue;
            }
            $d = (float) $candidate['distance'];
            if (null === $nearest || $d < $nearest) {
                $nearest = $d;
            }
        }

        return new BikeWayReading(true, $nearest);
    }

    /** @param array<array-key, mixed> $edge */
    private static function rideable(array $edge): bool
    {
        if (true !== ($edge['access']['bicycle'] ?? null)) {
            return false;
        }
        $sac = $edge['sac_scale'] ?? 'none';
        if (\is_string($sac) && '' !== $sac && 'none' !== $sac) {
            return false;
        }
        $use = $edge['classification']['use'] ?? null;
        if (\in_array($use, self::RIDE_USES, true)) {
            return 'motorway' !== ($edge['classification']['classification'] ?? null);
        }

        return \in_array($use, self::MARKED_USES, true)
            && \in_array($edge['cycle_lane'] ?? 'none', ['dedicated', 'separated'], true);
    }
}
