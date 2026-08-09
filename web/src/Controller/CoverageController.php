<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Coverage\CoverageRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
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
     * Hard cap on the region-id set a `rids` scope param may carry
     * (map-and-search.md §4.5 risk 10): a small, sorted id set keeps the
     * shared HTTP-cache keyspace bounded. A country scope always sends `cc`
     * alongside, whose OR arm covers every row even if the id list is capped,
     * so the cap is safe (never under-inclusive for a country). Real scopes
     * are tiny (Belgium: 3; a Phase-4 My-area set: 8).
     */
    private const int MAX_SCOPE_REGIONS = 24;

    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

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
        [$rids, $cc] = $this->scopeParams($request);
        $results = mb_strlen($q) >= 2 ? $coverage->search($q, $rids, $cc) : [];

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
        [$rids, $cc] = $this->scopeParams($request);

        return $this->cacheable($request, ['groups' => $coverage->nearby((float) $lat, (float) $lng, $km, $rids, $cc), 'attribution' => CoverageRepository::ATTRIBUTION], 300);
    }

    /** Rail totals (coverage-provider.md §5): per-letter coverage counts. */
    #[Route('/map/coverage/counts', name: 'map_coverage_counts', methods: ['GET'])]
    public function counts(Request $request, CoverageRepository $coverage, RateLimiterFactoryInterface $coverageReadLimiter): Response
    {
        if (null !== ($limited = $this->rateLimited($request, $coverageReadLimiter))) {
            return $limited;
        }

        [$rids, $cc] = $this->scopeParams($request);

        // (object) so an empty table still serves {"counts":{}}, a JSON
        // object, never [] (the client indexes by letter).
        return $this->cacheable($request, ['counts' => (object) $coverage->counts($rids, $cc), 'attribution' => CoverageRepository::ATTRIBUTION], 3600);
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
     * Parse the region-scope query params (map-and-search.md §4.5): `rids`
     * a csv of region ids compiled to a `region_id IN (…)` arm, `cc` a 2-letter
     * country code. Both are always client-sent, never server-resolved from a
     * user (the coverage plane is anonymous + cacheable — §6 cacheability
     * discipline). The id set is de-duped by numeric value and capped so the SQL
     * IN-list stays bounded (§8 risk 10); the cap is what keeps the query small,
     * NOT the HTTP-cache key — that key is the raw query string the client sends,
     * which the client already canonicalises (covScopeQuery: sorted, deduped).
     * Sorting here only keeps the DBAL binding stable across equivalent inputs.
     * Absent/garbage params yield the empty scope (Everywhere), i.e. current
     * behaviour — including array-valued (`rids[]=1`) params, which coerce to
     * Everywhere via all() instead of raising Symfony's HTML 400.
     *
     * @return array{0: list<int>, 1: ?string}
     */
    private function scopeParams(Request $request): array
    {
        // all() never throws on an array-valued param, unlike get(), so
        // `rids[]=1` / `cc[]=BE` degrade to Everywhere instead of a 400.
        $query = $request->query->all();
        $ridsRaw = \is_string($query['rids'] ?? null) ? $query['rids'] : '';

        $rids = [];
        foreach (explode(',', $ridsRaw) as $part) {
            $part = trim($part);
            // Canonical positive integer only, keyed by numeric value so a
            // zero-padded duplicate ('01' vs '1') collapses instead of eating a
            // cap slot. An overflow string (e.g. 20+ nines) saturates (int) to
            // PHP_INT_MAX and would round-trip to a different, non-canonical
            // string — reject it so garbage stays Everywhere, never a phantom
            // `region_id IN (9223372036854775807)` empty scope.
            if ('' === $part || !ctype_digit($part)) {
                continue;
            }
            $n = (int) $part;
            if ($n >= 1 && (string) $n === ltrim($part, '0')) {
                $rids[$n] = $n;   // key by int value → dedupe by identity
            }
        }
        $rids = array_values($rids);
        sort($rids);
        if (\count($rids) > self::MAX_SCOPE_REGIONS) {
            // A truncated scope is under-inclusive on rids alone; the cc arm is
            // the country-wide safety net (see MAX_SCOPE_REGIONS). Surface it so
            // a real >24-region scope (a future large country) is never a silent
            // hole rather than a diagnosable one.
            $this->logger->warning('Coverage scope rids capped at {cap} (received {count}); relying on the cc arm for completeness.', ['cap' => self::MAX_SCOPE_REGIONS, 'count' => \count($rids)]);
            $rids = \array_slice($rids, 0, self::MAX_SCOPE_REGIONS);
        }

        $cc = $query['cc'] ?? null;
        // /D so a trailing newline ("BE\n") can't slip past $ — PCRE's $ matches
        // before a final \n by default, which would keep the newline through
        // strtoupper and yield country_code = 'BE\n' matching nothing (the
        // inverse of the garbage-→-Everywhere contract).
        $cc = (\is_string($cc) && 1 === preg_match('/^[A-Za-z]{2}$/D', $cc)) ? strtoupper($cc) : null;

        return [$rids, $cc];
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
        // Session-independent by design (§6 anonymity) — without this,
        // LocaleSubscriber's session read lets AbstractSessionListener
        // downgrade the whole coverage plane to `private` for any visitor
        // carrying a session cookie (frontend review 2026-08-09 #1).
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setMaxAge($maxAge);
        $response->isNotModified($request);

        return $response;
    }
}
