<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Api\V1\CategoryTable;
use App\Api\V1\PublicItemsProvider;
use App\Coverage\CoverageManifest;
use App\Coverage\RoutesManifest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public API PoC (public-api.md §2.2; explained for consumers in
 * wiki/developers/api/): anonymous, CORS-open, cacheable read endpoints under
 * /v1. The CoverageController shape (shared rateLimited()/cacheable()
 * helpers, per-IP sliding window, ETag + public max-age) on its own limiter
 * pool so external consumers never eat the site map's budget.
 *
 * Every /v1 path needs its explicit PUBLIC_ACCESS entry in security.yaml
 * (the ^/v1/ rule): without it scheb's lazy-firewall session read downgrades
 * Cache-Control to private for any cookie-carrying visitor.
 *
 * @api Instantiated by Symfony's router; called by external consumers.
 */
final class PublicApiController extends AbstractController
{
    /**
     * Hard cap on the bbox span, degrees per axis: a viewport query never
     * legitimately spans a continent, and the cap keeps the envelope scan and
     * the response bounded. Whole-region reads belong to the data exports.
     */
    private const float BBOX_MAX_SPAN_DEG = 10.0;

    private const int LIMIT_DEFAULT = 100;
    private const int LIMIT_MAX = 500;

    /**
     * The consumer bootstrap (wiki/developers/api/serving-map-data.md): the
     * current routes-tiles URL, the source-layer naming contract, and the
     * rendering metadata tables. tilesUrl is null when no tileset is
     * published or the manifest is unreachable; consumers skip the routes
     * overlay then, so an outage degrades to "no corridors", never an error.
     */
    #[Route('/v1/map-config', name: 'api_v1_map_config', methods: ['GET'])]
    public function mapConfig(Request $request, RoutesManifest $routesManifest, CoverageManifest $coverageManifest, RateLimiterFactoryInterface $publicApiReadLimiter): Response
    {
        if (null !== ($limited = $this->rateLimited($request, $publicApiReadLimiter))) {
            return $limited;
        }

        $payload = [
            'version' => '0.1',
            'attribution' => CategoryTable::ATTRIBUTION,
            'routes' => [
                'tilesUrl' => $routesManifest->tilesUrl(),
                // Lowercased to match the tile source-layer names, which the
                // pipeline derives the same way (routes-tiles.js lineLayers()).
                'countries' => array_map(strtolower(...), $coverageManifest->countryCodes()),
                'sourceLayers' => ['lines' => 'routes_{cc}', 'nodes' => 'knoop_{cc}'],
                'style' => [
                    'groups' => CategoryTable::ROUTE_STYLE_GROUPS,
                    'badgeMinZoom' => CategoryTable::ROUTE_BADGE_MIN_ZOOM,
                ],
            ],
            // The dense "everything" layer: raw OSM coverage as tiles, one
            // source-layer per (letter, country) plus the unstamped bucket.
            // Colour by letter from `categories`; individual points exist in
            // the tiles from minZoom.
            'coverage' => [
                'tilesUrl' => $coverageManifest->currentTileUrl(),
                'countries' => [...array_map(strtolower(...), $coverageManifest->countryCodes()), CategoryTable::COVERAGE_UNSTAMPED_BUCKET],
                'sourceLayers' => ['points' => '{letter}_{cc}'],
                'letters' => CategoryTable::COVERAGE_LETTERS,
                'minZoom' => CategoryTable::COVERAGE_MIN_ZOOM,
            ],
            'categories' => CategoryTable::CATEGORIES,
        ];

        return $this->cacheable($request, $payload, 3600);
    }

    /**
     * Viewport GeoJSON over the curated item table (public-api.md §2.2
     * /v1/search, PoC subset: bbox + optional letter + optional tier + limit;
     * q/hydrate/cursor stay draft). Letters without item-table rows (A, K
     * live elsewhere) answer an empty collection, not an error: valid
     * letter, nothing there.
     */
    #[Route('/v1/search', name: 'api_v1_search', methods: ['GET'])]
    public function search(Request $request, PublicItemsProvider $items, RateLimiterFactoryInterface $publicApiReadLimiter): Response
    {
        if (null !== ($limited = $this->rateLimited($request, $publicApiReadLimiter))) {
            return $limited;
        }

        $query = $request->query->all();

        // Optional since the mode-slider round: absent = all letters, one
        // viewport request instead of one per letter. /D so a trailing
        // newline can't ride past $ (the CoverageController scopeParams()
        // lesson).
        $letter = $query['letter'] ?? null;
        if (null !== $letter && (!\is_string($letter) || 1 !== preg_match('/^[A-M]$/D', $letter))) {
            return $this->badRequest('invalid_letter', 'letter must be one catalogue letter A-M (see /v1/map-config categories), or absent for all');
        }

        $tier = $query['tier'] ?? null;
        if (null !== $tier && (!\is_string($tier) || !\in_array($tier, ['community', 'curated'], true))) {
            return $this->badRequest('invalid_tier', 'tier must be community or curated, or absent for both');
        }

        $bbox = $this->parseBbox(\is_string($query['bbox'] ?? null) ? $query['bbox'] : '');
        if (null === $bbox) {
            return $this->badRequest('invalid_bbox', 'bbox is required: minLon,minLat,maxLon,maxLat in WGS84, min < max');
        }
        if ($bbox[2] - $bbox[0] > self::BBOX_MAX_SPAN_DEG || $bbox[3] - $bbox[1] > self::BBOX_MAX_SPAN_DEG) {
            return $this->badRequest('bbox_too_large', \sprintf('bbox may span at most %.0fx%.0f degrees', self::BBOX_MAX_SPAN_DEG, self::BBOX_MAX_SPAN_DEG));
        }

        // Garbage limits degrade to the default rather than a 400: the two
        // spatial params carry the contract, the limit is a courtesy knob.
        $limit = $query['limit'] ?? null;
        $limit = (\is_string($limit) && ctype_digit($limit)) ? (int) $limit : self::LIMIT_DEFAULT;
        $limit = min(self::LIMIT_MAX, max(1, $limit));

        $payload = [
            'type' => 'FeatureCollection',
            'features' => array_map(static fn ($f) => $f->toGeoJson(), $items->featuresInBbox($letter, $bbox, $limit, $tier)),
            'licence' => 'ODbL-1.0',
            'attribution' => CategoryTable::ATTRIBUTION,
        ];

        return $this->cacheable($request, $payload, 300, 'application/geo+json');
    }

    /**
     * Strict bbox parse: four floats, lon/lat ranges, min < max on both axes.
     * Returns null on any violation; the caller answers invalid_bbox with
     * the full expected shape, so a consumer never has to guess which of the
     * four numbers offended.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private function parseBbox(string $raw): ?array
    {
        $parts = explode(',', $raw);
        if (4 !== \count($parts)) {
            return null;
        }
        $nums = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (!is_numeric($part)) {
                return null;
            }
            $nums[] = (float) $part;
        }
        [$minLon, $minLat, $maxLon, $maxLat] = $nums;
        if (abs($minLon) > 180.0 || abs($maxLon) > 180.0 || abs($minLat) > 90.0 || abs($maxLat) > 90.0) {
            return null;
        }
        if ($minLon >= $maxLon || $minLat >= $maxLat) {
            return null;
        }

        return [$minLon, $minLat, $maxLon, $maxLat];
    }

    /** Error contract (public-api.md §2.2): JSON { error, message }, conventional status. */
    private function badRequest(string $error, string $message): JsonResponse
    {
        return $this->json(['error' => $error, 'message' => $message], 400);
    }

    /**
     * public_api_read enforcement: the CoverageController::rateLimited()
     * pattern verbatim: per-IP sliding window, consume-or-429, Retry-After
     * from the limiter's own instant.
     */
    private function rateLimited(Request $request, RateLimiterFactoryInterface $limiter): ?JsonResponse
    {
        $limit = $limiter->create('ip-'.($request->getClientIp() ?? 'unknown'))->consume();
        if ($limit->isAccepted()) {
            return null;
        }

        $response = $this->json(['error' => 'rate_limited', 'message' => 'over 120 requests per minute; slow down'], 429);
        $retryAfter = max(0, $limit->getRetryAfter()->getTimestamp() - time());
        $response->headers->set('Retry-After', (string) $retryAfter);

        return $response;
    }

    /**
     * ETag + public max-age (the CoverageController::cacheable() pattern);
     * NO_AUTO_CACHE_CONTROL_HEADER so a session cookie on the request can't
     * downgrade the response to private.
     *
     * @param array<string, mixed> $payload
     */
    private function cacheable(Request $request, array $payload, int $maxAge, ?string $contentType = null): Response
    {
        $json = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        if (null !== $contentType) {
            $response->headers->set('Content-Type', $contentType);
        }
        $response->setEtag(md5($json));
        $response->setPublic();
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setMaxAge($maxAge);
        $response->isNotModified($request);

        return $response;
    }
}
