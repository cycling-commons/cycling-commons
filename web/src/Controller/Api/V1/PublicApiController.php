<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
 * Anonymous public read API.
 *
 * @see docs/specs/public-api.md §2.2
 *
 * @api
 */
final class PublicApiController extends AbstractController
{
    /** Viewport bbox cap (degrees per axis). */
    private const float BBOX_MAX_SPAN_DEG = 10.0;

    private const int LIMIT_DEFAULT = 100;
    private const int LIMIT_MAX = 500;

    /**
     * @see docs/specs/public-api.md §2.2
     */
    #[Route('/v1/map-config', name: 'api_v1_map_config', methods: ['GET'])]
    public function mapConfig(Request $request, RoutesManifest $routesManifest, CoverageManifest $coverageManifest, RateLimiterFactoryInterface $publicApiReadLimiter): Response
    {
        if (null !== ($limited = $this->rateLimited($request, $publicApiReadLimiter))) {
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

        return $this->cacheable($request, $payload, 3600);
    }

    /**
     * @see docs/specs/public-api.md §2.2
     */
    #[Route('/v1/search', name: 'api_v1_search', methods: ['GET'])]
    public function search(Request $request, PublicItemsProvider $items, RateLimiterFactoryInterface $publicApiReadLimiter): Response
    {
        if (null !== ($limited = $this->rateLimited($request, $publicApiReadLimiter))) {
            return $limited;
        }

        $query = $request->query->all();

        $letter = $query['letter'] ?? null;
        // Only letters the catalogue actually defines: a free letter in a half
        // (practical A-M, experiential N-Z) is not a category yet, so it is a
        // 400 like any other unknown value, not an empty 200.
        $known = array_column(CategoryTable::CATEGORIES, 'letter');
        if (null !== $letter && (!\is_string($letter) || !\in_array($letter, $known, true))) {
            return $this->badRequest('invalid_letter', 'letter must be one catalogue letter (see /v1/map-config categories: practical A-M, experiential N-Z), or absent for all');
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

    /** @see docs/specs/public-api.md §2.2 */
    private function badRequest(string $error, string $message): JsonResponse
    {
        return $this->json(['error' => $error, 'message' => $message], 400);
    }

    /** Per-IP limiter; 429 + Retry-After. */
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
     * Public cache; do not let a session cookie downgrade it.
     *
     * @see docs/specs/account-and-auth.md §5
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
