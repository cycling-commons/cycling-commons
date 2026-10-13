<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Api\V1\ApiResponder;
use App\Api\V1\CategoryTable;
use App\Api\V1\PublicCatalog;
use App\Api\V1\PublicItemsProvider;
use App\Coverage\CoverageManifest;
use App\Coverage\RoutesManifest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Anonymous public read API: the catalogue and the items.
 *
 * @see docs/specs/public-api.md §2.2
 *
 * @api
 */
final class PublicApiController extends AbstractController
{
    /**
     * Name search bounds. Three letters, because the name index is a trigram
     * index (Version20260928180000): shorter words cannot use it and would
     * read every row.
     */
    private const int Q_MIN = 3;
    private const int Q_MAX = 100;

    /**
     * The counts read every OpenStreetMap point in the box, so the box is
     * smaller than a search's: two degrees is a province, about half a
     * second over the Benelux on dev (measured 2026-10-12).
     */
    private const float COUNTS_MAX_SPAN_DEG = 2.0;

    public function __construct(private readonly ApiResponder $api)
    {
    }

    /**
     * @see docs/specs/public-api.md §2.2
     */
    #[Route('/v1/map-config', name: 'api_v1_map_config', methods: ['GET'])]
    public function mapConfig(Request $request, RoutesManifest $routesManifest, CoverageManifest $coverageManifest): Response
    {
        if (null !== ($limited = $this->api->rateLimited($request))) {
            return $limited;
        }

        // Both manifests come from the bucket. Started together they cost one
        // round trip, read one after the other they cost two.
        $routesManifest->prefetch();
        $coverageManifest->prefetch();

        $payload = [
            'version' => '0.2',
            'attribution' => CategoryTable::ATTRIBUTION,
            'routes' => [
                'tiles' => $routesManifest->countryTiles(),
                'countries' => array_map(strtolower(...), $coverageManifest->countryCodes()),
                'sourceLayers' => ['lines' => 'routes_{cc}', 'nodes' => 'knoop_{cc}'],
                'style' => [
                    'groups' => CategoryTable::ROUTE_STYLE_GROUPS,
                    'badgeMinZoom' => CategoryTable::ROUTE_BADGE_MIN_ZOOM,
                ],
            ],
            'coverage' => [
                'tiles' => $coverageManifest->countryTiles(),
                'countries' => [...array_map(strtolower(...), $coverageManifest->countryCodes()), CategoryTable::COVERAGE_UNSTAMPED_BUCKET],
                'sourceLayers' => ['points' => '{letter}_{cc}'],
                'letters' => CategoryTable::COVERAGE_LETTERS,
                'minZoom' => CategoryTable::COVERAGE_MIN_ZOOM,
            ],
            'categories' => CategoryTable::categories(),
        ];

        return $this->api->cacheable($request, $payload, 3600);
    }

    /**
     * @see docs/specs/public-api.md §2.2
     */
    #[Route('/v1/catalog', name: 'api_v1_catalog', methods: ['GET'])]
    public function catalog(Request $request, PublicCatalog $catalog): Response
    {
        if (null !== ($limited = $this->api->rateLimited($request))) {
            return $limited;
        }

        return $this->api->cacheable($request, $catalog->build(), 3600);
    }

    /**
     * @see docs/specs/public-api.md §2.2
     */
    #[Route('/v1/search', name: 'api_v1_search', methods: ['GET'])]
    public function search(Request $request, PublicItemsProvider $items): Response
    {
        if (null !== ($limited = $this->api->rateLimited($request))) {
            return $limited;
        }

        $query = $request->query->all();

        $letter = $query['letter'] ?? null;
        // Only letters the catalogue actually defines: a free letter in a half
        // (practical A-M, experiential N-Z) is not a category yet, so it is a
        // 400 like any other unknown value, not an empty 200.
        $known = array_column(CategoryTable::CATEGORIES, 'letter');
        if (null !== $letter && (!\is_string($letter) || !\in_array($letter, $known, true))) {
            return $this->api->error('invalid_letter', 'letter must be one catalogue letter (see /v1/catalog: practical A-M, experiential N-Z), or absent for all');
        }

        $tier = $query['tier'] ?? null;
        if (null !== $tier && (!\is_string($tier) || !\in_array($tier, ['community', 'curated'], true))) {
            return $this->api->error('invalid_tier', 'tier must be community or curated, or absent for both');
        }

        // Routes join the answer only when asked for: a consumer that never
        // asked keeps the answer it had.
        $routes = $query['routes'] ?? null;
        if (null !== $routes && 'include' !== $routes) {
            return $this->api->error('invalid_routes', 'routes must be include, or absent for items only (letter=R gives routes only)');
        }

        $hydrate = $this->hydrate($query);
        if (null === $hydrate) {
            return $this->api->error('invalid_hydrate', 'hydrate must be osm, or absent');
        }

        $q = $query['q'] ?? null;
        if (null !== $q) {
            $q = \is_string($q) ? trim($q) : '';
            $length = mb_strlen($q);
            if ($length < self::Q_MIN || $length > self::Q_MAX) {
                return $this->api->error('invalid_q', \sprintf('q must be %d to %d characters of a name', self::Q_MIN, self::Q_MAX));
            }
        }

        // A name search is one page of its best matches; only a browse pages.
        $after = ApiResponder::after($query['cursor'] ?? null);
        // A search cursor holds `i` (the last item) or `r` (the last route), as ints.
        if (null !== $after && ([] !== array_diff(array_keys($after), ['i', 'r']) || [] !== array_filter($after, static fn (mixed $v): bool => !\is_int($v)))) {
            $after = [];
        }
        if ([] === $after || (null !== $after && null !== $q)) {
            return $this->api->error('invalid_cursor', null !== $q ? 'a name search answers in one page; cursor pages a bbox browse' : 'cursor must be the next_cursor of a previous page');
        }

        // A name narrows the search enough on its own; without one, the box
        // is what keeps the answer small.
        $bbox = null;
        if (null === $q || isset($query['bbox'])) {
            $bbox = $this->api->bbox($query['bbox'] ?? null);
            if (null === $bbox) {
                return $this->api->error('invalid_bbox', 'bbox is required unless q is given: minLon,minLat,maxLon,maxLat in WGS84, min < max');
            }
            $max = ApiResponder::BBOX_MAX_SPAN_DEG;
            if ($bbox[2] - $bbox[0] > $max || $bbox[3] - $bbox[1] > $max) {
                return $this->api->error('bbox_too_large', \sprintf('bbox may span at most %.0fx%.0f degrees', $max, $max));
            }
        }

        $result = $items->search($letter, $bbox, $q, $this->api->limit($query['limit'] ?? null), $tier, null !== $routes, $hydrate, $after);
        $payload = [
            'type' => 'FeatureCollection',
            'features' => array_map(static fn ($f) => $f->toGeoJson(), $result['features']),
            'licence' => 'ODbL-1.0',
            'attribution' => CategoryTable::ATTRIBUTION,
        ];
        if (null !== $result['next']) {
            $payload['next_cursor'] = ApiResponder::cursor($result['next']);
        }

        return $this->api->cacheable($request, $payload, 300, 'application/geo+json');
    }

    /**
     * @see docs/specs/public-api.md §2.2
     */
    #[Route('/v1/items/{id}', name: 'api_v1_item', requirements: ['id' => '\d{1,18}'], methods: ['GET'])]
    public function item(Request $request, int $id, PublicItemsProvider $items): Response
    {
        if (null !== ($limited = $this->api->rateLimited($request))) {
            return $limited;
        }
        $hydrate = $this->hydrate($request->query->all());
        if (null === $hydrate) {
            return $this->api->error('invalid_hydrate', 'hydrate must be osm, or absent');
        }

        $item = $items->item($id, $hydrate);
        if (null === $item) {
            return $this->api->notFound(\sprintf('no served item %d', $id));
        }

        return $this->api->cacheable($request, $item->toGeoJson() + [
            'licence' => 'ODbL-1.0',
            'attribution' => CategoryTable::ATTRIBUTION,
        ], 300, 'application/geo+json');
    }

    /**
     * @see docs/specs/public-api.md §2.2
     */
    #[Route('/v1/coverage/counts', name: 'api_v1_coverage_counts', methods: ['GET'])]
    public function coverageCounts(Request $request, PublicItemsProvider $items): Response
    {
        if (null !== ($limited = $this->api->rateLimited($request))) {
            return $limited;
        }
        $bbox = $this->api->requiredBbox($request->query->all()['bbox'] ?? null, self::COUNTS_MAX_SPAN_DEG);
        if (!\is_array($bbox)) {
            return $bbox;
        }

        $counts = $items->counts($bbox);

        return $this->api->cacheable($request, [
            'bbox' => $bbox,
            'counts' => (object) $counts['items'],
            'coverage' => (object) $counts['coverage'],
            'attribution' => CategoryTable::ATTRIBUTION,
        ], 300);
    }

    /**
     * `hydrate`: true for `osm`, false when absent, null for anything else.
     *
     * @param array<string, mixed> $query
     */
    private function hydrate(array $query): ?bool
    {
        $hydrate = $query['hydrate'] ?? null;

        return null === $hydrate ? false : ('osm' === $hydrate ? true : null);
    }
}
