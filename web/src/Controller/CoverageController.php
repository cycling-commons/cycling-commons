<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Coverage\CoverageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The coverage query plane (coverage-provider.md §5):
 * anonymous, cacheable JSON over the pipeline-owned coverage_poi cache.
 * Site-internal map endpoints, not a public API — the serving cache
 * may return OSM fields with attribution (osm-data-architecture.md §4).
 * Every action consumes the coverage_read limiter (120/min per IP), the
 * app's first anonymous-read limiter.
 *
 * @api Instantiated by Symfony's router; called by assets/map/map.js.
 */
final class CoverageController extends AbstractController
{
    /**
     * Hard cap on the search term before it reaches ILIKE/similarity —
     * typed map queries are short; anything longer is noise or abuse.
     */
    private const int SEARCH_QUERY_MAX_LENGTH = 64;

    /**
     * Sidebar search over the coverage tier: curated matches first, then
     * community rows not shadowed by a served ref. Queries under two chars
     * answer an empty result set (cheap contract for the client debounce).
     */
    #[Route('/map/coverage/search', name: 'map_coverage_search', methods: ['GET'])]
    public function search(Request $request, CoverageRepository $coverage, RateLimiterFactoryInterface $coverageReadLimiter): Response
    {
        if (null !== ($limited = $this->rateLimited($request, $coverageReadLimiter))) {
            return $limited;
        }

        $q = mb_substr(trim((string) $request->query->get('q', '')), 0, self::SEARCH_QUERY_MAX_LENGTH);
        $results = mb_strlen($q) >= 2 ? $coverage->search($q) : [];

        return $this->cacheable($request, ['results' => $results, 'attribution' => CoverageRepository::ATTRIBUTION], 300);
    }

    /**
     * Town-card nearby (coverage-provider.md §5): letter groups within :km of a point,
     * curated first, community capped behind the client's "show all" expander.
     */
    #[Route('/map/coverage/nearby', name: 'map_coverage_nearby', methods: ['GET'])]
    public function nearby(Request $request, CoverageRepository $coverage, RateLimiterFactoryInterface $coverageReadLimiter): Response
    {
        if (null !== ($limited = $this->rateLimited($request, $coverageReadLimiter))) {
            return $limited;
        }

        $lat = $request->query->get('lat');
        $lng = $request->query->get('lng');
        if (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90.0 || abs((float) $lng) > 180.0) {
            return $this->json(['error' => 'invalid_coords'], 422);
        }
        $km = $request->query->get('km');
        $km = is_numeric($km) ? min(25.0, max(0.1, (float) $km)) : 5.0;

        return $this->cacheable($request, ['groups' => $coverage->nearby((float) $lat, (float) $lng, $km), 'attribution' => CoverageRepository::ATTRIBUTION], 300);
    }

    /** Rail totals (coverage-provider.md §5): per-letter coverage counts. */
    #[Route('/map/coverage/counts', name: 'map_coverage_counts', methods: ['GET'])]
    public function counts(Request $request, CoverageRepository $coverage, RateLimiterFactoryInterface $coverageReadLimiter): Response
    {
        if (null !== ($limited = $this->rateLimited($request, $coverageReadLimiter))) {
            return $limited;
        }

        // (object) so an empty table still serves {"counts":{}}, a JSON
        // object, never [] (the client indexes by letter).
        return $this->cacheable($request, ['counts' => (object) $coverage->counts(), 'attribution' => CoverageRepository::ATTRIBUTION], 3600);
    }

    /**
     * Drawer detail for a tile POI: display-whitelisted cached OSM tags +
     * curated overlay where a served item shares the ref. 404 for refs the
     * coverage cache does not hold.
     */
    #[Route('/map/coverage/poi/{osmType}/{osmId}', name: 'map_coverage_poi', requirements: ['osmType' => 'node|way', 'osmId' => '\d+'], methods: ['GET'])]
    public function poi(string $osmType, int $osmId, Request $request, CoverageRepository $coverage, RateLimiterFactoryInterface $coverageReadLimiter): Response
    {
        if (null !== ($limited = $this->rateLimited($request, $coverageReadLimiter))) {
            return $limited;
        }

        $detail = $coverage->detail($osmType, $osmId);
        if (null === $detail) {
            throw $this->createNotFoundException('No coverage POI.');
        }

        return $this->cacheable($request, $detail, 300);
    }

    /**
     * coverage_read enforcement (coverage-provider.md §5: sliding window, 120/min per IP) —
     * the RideCheckController consume-or-429 pattern, keyed by client IP
     * because the whole plane is anonymous. The 429 carries a Retry-After
     * header (seconds) derived from the limiter's own retry-after instant, so
     * an anonymous client (or a well-behaved scraper) knows exactly when to
     * come back instead of hammering the plane immediately.
     */
    private function rateLimited(Request $request, RateLimiterFactoryInterface $limiter): ?JsonResponse
    {
        $limit = $limiter->create('ip-'.($request->getClientIp() ?? 'unknown'))->consume();
        if ($limit->isAccepted()) {
            return null;
        }

        $response = $this->json(['error' => 'rate_limited'], 429);
        $retryAfter = max(0, $limit->getRetryAfter()->getTimestamp() - time());
        $response->headers->set('Retry-After', (string) $retryAfter);

        return $response;
    }

    /**
     * ETag + public max-age (the MapController::catalog() pattern); encode
     * flags match CatalogProvider::json() so served bytes stay byte-stable.
     *
     * @param array<string, mixed> $payload
     */
    private function cacheable(Request $request, array $payload, int $maxAge): Response
    {
        $json = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        $response->setEtag(md5($json));
        $response->setPublic();
        $response->setMaxAge($maxAge);
        $response->isNotModified($request);

        return $response;
    }
}
