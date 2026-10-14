<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Elevation;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The surfaces under a planned leg (docs/specs/route-planner.md section 4.4),
 * from our own routing engine: `/trace_attributes` walks the leg's line edge
 * by edge and says each edge's surface and length, and this class folds the
 * engine's surface words into the three a rider plans with, paved, gravel
 * and rough, plus unknown, in metres. Null when there is no engine, it does
 * not answer, or it finds no edge: the figures then leave the surface out.
 *
 * @api
 */
final class LegSurfaces
{
    /** The engine's surface words, folded (docs/specs/route-planner.md section 4.4). */
    private const array CLASS_OF = [
        'paved_smooth' => 'paved',
        'paved' => 'paved',
        'paved_rough' => 'rough',
        'compacted' => 'gravel',
        'gravel' => 'gravel',
        'dirt' => 'rough',
        'path' => 'rough',
        'impassable' => 'rough',
    ];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $valhallaUrl,
        private readonly ?ElevationEndpoints $endpoints = null,
    ) {
    }

    /**
     * @param list<array{0: float, 1: float}> $coords [lat, lng] pairs, the routed line
     *
     * @return array{paved: int, gravel: int, rough: int, unknown: int}|null metres per class
     */
    public function along(array $coords): ?array
    {
        if (\count($coords) < 2 || '' === $this->valhallaUrl) {
            return null;
        }
        $url = $this->endpoints?->forShape($coords) ?? $this->valhallaUrl;
        $shape = array_map(static fn (array $c): array => ['lat' => $c[0], 'lon' => $c[1]], $coords);
        try {
            $res = $this->http->request('POST', rtrim($url, '/').'/trace_attributes', [
                'json' => [
                    'shape' => $shape,
                    'costing' => 'bicycle',
                    'shape_match' => 'edge_walk',
                    'units' => 'kilometers',
                    'filters' => ['attributes' => ['edge.surface', 'edge.length'], 'action' => 'include'],
                ],
                'timeout' => 8,
            ]);
            $data = $res->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('leg surfaces lookup failed', ['error' => $e->getMessage()]);

            return null;
        }
        $edges = $data['edges'] ?? null;
        if (!\is_array($edges) || [] === $edges) {
            return null;
        }
        $metres = ['paved' => 0.0, 'gravel' => 0.0, 'rough' => 0.0, 'unknown' => 0.0];
        foreach ($edges as $edge) {
            if (!\is_array($edge) || !isset($edge['length']) || !is_numeric($edge['length'])) {
                continue;
            }
            $class = self::CLASS_OF[\is_string($edge['surface'] ?? null) ? $edge['surface'] : ''] ?? 'unknown';
            $metres[$class] += (float) $edge['length'] * 1000.0;
        }

        return array_map(static fn (float $m): int => (int) round($m), $metres);
    }
}
