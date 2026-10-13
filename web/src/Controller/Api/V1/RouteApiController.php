<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Api\V1\ApiResponder;
use App\Api\V1\CategoryTable;
use App\Api\V1\PublicRoutesProvider;
use App\Catalog\RouteTrack;
use App\Contribution\Gpx\GpxWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Recommended routes with their whole line, and as GPX.
 *
 * @see docs/specs/public-api.md §2.2
 *
 * @api
 */
final class RouteApiController extends AbstractController
{
    /** A whole line weighs more than a point: pages stay smaller than a search's. */
    private const int LIMIT_MAX = 100;

    public function __construct(private readonly ApiResponder $api)
    {
    }

    #[Route('/v1/routes', name: 'api_v1_routes', methods: ['GET'])]
    public function list(Request $request, PublicRoutesProvider $routes): Response
    {
        if (null !== ($limited = $this->api->rateLimited($request))) {
            return $limited;
        }
        $query = $request->query->all();

        $bbox = $this->api->requiredBbox($query['bbox'] ?? null);
        if (!\is_array($bbox)) {
            return $bbox;
        }
        $tier = $query['tier'] ?? null;
        if (null !== $tier && (!\is_string($tier) || !\in_array($tier, ['community', 'curated'], true))) {
            return $this->api->error('invalid_tier', 'tier must be community or curated, or absent for both');
        }
        $after = ApiResponder::after($query['cursor'] ?? null);
        if ([] === $after || (null !== $after && !\is_int($after['r'] ?? null))) {
            return $this->api->error('invalid_cursor', 'cursor must be the next_cursor of a previous page');
        }

        $limit = $this->api->limit($query['limit'] ?? null, self::LIMIT_MAX);
        $lines = $routes->inBox($bbox, $limit + 1, (int) ($after['r'] ?? 0), $tier);
        $page = \array_slice($lines, 0, $limit);
        $payload = [
            'type' => 'FeatureCollection',
            'features' => array_map(static fn ($l) => $l->toGeoJson(), $page),
            'licence' => 'ODbL-1.0',
            'attribution' => CategoryTable::ATTRIBUTION,
        ];
        if (\count($lines) > $limit) {
            $payload['next_cursor'] = ApiResponder::cursor(['r' => $page[$limit - 1]->id]);
        }

        return $this->api->cacheable($request, $payload, 300, 'application/geo+json');
    }

    #[Route('/v1/routes/{id}', name: 'api_v1_route', requirements: ['id' => '\d{1,18}'], methods: ['GET'])]
    public function route(Request $request, int $id, PublicRoutesProvider $routes): Response
    {
        if (null !== ($limited = $this->api->rateLimited($request))) {
            return $limited;
        }
        $route = $routes->route($id);
        if (null === $route) {
            return $this->api->notFound(\sprintf('no served route %d', $id));
        }

        return $this->api->cacheable($request, $route->toGeoJson() + [
            'licence' => 'ODbL-1.0',
            'attribution' => CategoryTable::ATTRIBUTION,
        ], 300, 'application/geo+json');
    }

    /**
     * The same track the site's `/routes/{id}.gpx` downloads (RouteTrack).
     */
    #[Route('/v1/routes/{id}.gpx', name: 'api_v1_route_gpx', requirements: ['id' => '\d{1,18}'], methods: ['GET'])]
    public function gpx(Request $request, int $id, RouteTrack $tracks, GpxWriter $writer): Response
    {
        if (null !== ($limited = $this->api->rateLimited($request))) {
            return $limited;
        }
        $track = $tracks->find($id);
        if (null === $track) {
            return $this->api->notFound(\sprintf('no served route %d', $id));
        }

        $gpx = $writer->write($track['name'], $track['coordinates']);
        $response = new Response($gpx, Response::HTTP_OK, [
            'Content-Type' => 'application/gpx+xml',
            'Content-Disposition' => \sprintf('attachment; filename="%s.gpx"', RouteTrack::fileName($track['name'])),
        ]);

        return $this->api->publicCache($request, $response, md5($gpx), 3600);
    }
}
