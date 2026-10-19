<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use App\Elevation\LegSurfaces;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * What a recommended route's roads are (docs/specs/route-domain.md §6.4, owner 2026-10-19: "A recommended route
 * should also have the road 'quality' info. Like when you plan your own route"): the surfaces, the main roads, the
 * smoothness, the track grades and the speed limits along its own line, read by our engine as a planned leg is
 * (LegSurfaces::readLine()). Kept a day per route and line: a route's line rarely changes, and the recorded surfaces
 * are loaded weekly.
 *
 * @api
 */
final class RouteRoads
{
    private const int KEEP_S = 86400;

    public function __construct(
        private readonly Connection $db,
        private readonly LegSurfaces $surfaces,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return array<string, mixed>|null null for no such route, or when the engine does not answer
     */
    public function forRoute(int $routeId, bool $allowSubmitted): ?array
    {
        $states = $allowSubmitted ? ItemState::servedOrSubmittedSqlTuple() : ItemState::servedSqlTuple();
        $geom = $this->db->fetchOne(
            'SELECT ST_AsGeoJSON(geom) FROM recommended_route WHERE id = :id AND state IN '.$states.' AND geom IS NOT NULL',
            ['id' => $routeId],
        );
        if (!\is_string($geom)) {
            return null;
        }
        $line = json_decode($geom, true);
        if (!\is_array($line) || 'LineString' !== ($line['type'] ?? null) || !\is_array($line['coordinates'] ?? null)) {
            return null;
        }
        /** @var list<array{0: float, 1: float}> $coords */
        $coords = array_values(array_map(static fn (array $c): array => [(float) $c[1], (float) $c[0]], $line['coordinates']));

        $cacheName = 'route_roads.v1.'.$routeId.'.'.md5($geom);
        $hit = $this->cache->get($cacheName, function (ItemInterface $item) use ($coords): array {
            $read = $this->surfaces->readLine($coords);
            // No answer is kept a minute only: the engine may be back soon.
            $item->expiresAfter(null === $read ? 60 : self::KEEP_S);

            return ['read' => $read];
        });

        return \is_array($hit['read'] ?? null) ? $hit['read'] : null;
    }
}
