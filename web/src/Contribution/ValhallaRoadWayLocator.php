<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

use App\Elevation\ElevationEndpoints;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The ways under a drawn road, from our Valhalla's `/trace_attributes`: the line matched to the roads (`map_snap`,
 * the line is the router's own answer to the two pins, so it lies on them), each edge's way id and length summed per
 * way. A way the line only touches, under MIN_M, is left out: the snap at a junction reads a few metres of the
 * crossing road.
 *
 * @api
 */
final class ValhallaRoadWayLocator implements RoadWayLocator
{
    /** A way the line follows for less than this, metres, is a junction it crosses, not a road it covers. */
    private const float MIN_M = 20.0;
    /** The most ways one road records, as the form's own `_ways_spanned`. */
    private const int MAX_WAYS = 120;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $valhallaUrl,
        private readonly ?ElevationEndpoints $endpoints = null,
    ) {
    }

    #[\Override]
    public function waysAlong(array $line): array
    {
        if ('' === $this->valhallaUrl || \count($line) < 2) {
            return [];
        }
        $latLng = array_map(static fn (array $p): array => [$p[1], $p[0]], $line);
        $url = $this->endpoints?->forShape($latLng) ?? $this->valhallaUrl;
        try {
            $data = $this->http->request('POST', rtrim($url, '/').'/trace_attributes', [
                'json' => [
                    'shape' => array_map(static fn (array $p): array => ['lat' => $p[0], 'lon' => $p[1]], $latLng),
                    'costing' => 'bicycle',
                    'shape_match' => 'map_snap',
                    'filters' => ['attributes' => ['edge.way_id', 'edge.length'], 'action' => 'include'],
                ],
                'timeout' => 8,
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('road way lookup failed', ['error' => $e->getMessage()]);

            return [];
        }
        $metres = [];
        foreach (\is_array($data['edges'] ?? null) ? $data['edges'] : [] as $edge) {
            $way = \is_array($edge) && is_numeric($edge['way_id'] ?? null) ? (int) $edge['way_id'] : 0;
            if ($way > 0 && is_numeric($edge['length'] ?? null)) {
                $metres[$way] = ($metres[$way] ?? 0.0) + (float) $edge['length'] * 1000.0;
            }
        }
        $metres = array_filter($metres, static fn (float $m): bool => $m >= self::MIN_M);
        arsort($metres);

        return \array_slice(array_map(static fn (int $way): string => 'way/'.$way, array_keys($metres)), 0, self::MAX_WAYS);
    }
}
