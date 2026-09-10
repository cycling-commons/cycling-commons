<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Coverage\CoverageRepository;
use App\Media\Commons\CommonsFile;
use App\Media\Commons\CommonsPhotoAdmission;
use App\Media\Commons\WikidataImageRepository;
use App\Media\ContinentResolver;
use App\Media\Message\ResolveWikidataImage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Anonymous coverage query plane.
 *
 * @see docs/specs/coverage-provider.md §5
 * @see docs/specs/security-architecture.md §7
 *
 * @api
 */
final class CoverageController extends AbstractController
{
    use ThirdPartyBudget;

    /** Search-term cap before ILIKE. */
    private const int SEARCH_QUERY_MAX_LENGTH = 64;

    /**
     * How many times a transient Commons failure is worth re-queueing.
     *
     * @see docs/specs/coverage-provider.md §7
     */

    /** Cap on `rids` so the IN-list stays bounded.
     *
     * @see docs/specs/map-and-search.md §4.5
     */
    private const int MAX_SCOPE_REGIONS = 24;

    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    /** Coverage search; <2 chars → empty. */
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
     * @see docs/specs/coverage-provider.md §5
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

    /** Per-letter rail totals.
     *
     * @see docs/specs/coverage-provider.md §5
     */
    #[Route('/map/coverage/counts', name: 'map_coverage_counts', methods: ['GET'])]
    public function counts(Request $request, CoverageRepository $coverage, RateLimiterFactoryInterface $coverageReadLimiter): Response
    {
        if (null !== ($limited = $this->rateLimited($request, $coverageReadLimiter))) {
            return $limited;
        }

        [$rids, $cc] = $this->scopeParams($request);

        return $this->cacheable($request, ['counts' => (object) $coverage->counts($rids, $cc), 'attribution' => CoverageRepository::ATTRIBUTION], 3600);
    }

    /** Drawer detail; 404 unknown refs. */
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
     * Live state of one POI's cached Wikimedia Commons photo.
     *
     * Never cached: this is the one thing that changes while a rider watches
     * the spinner, and `poi` above is cached for 300 seconds.
     *
     * The client names a POI and never a file. The filename is resolved from
     * our own tags, so nobody can hand us a name and make us download an
     * arbitrary file from Commons.
     *
     * @see docs/specs/coverage-provider.md §7
     */
    #[Route('/map/coverage/photo/{osmType}/{osmId}', name: 'map_coverage_photo', requirements: ['osmType' => 'node|way', 'osmId' => '\d+'], methods: ['GET'])]
    public function photo(
        string $osmType,
        int $osmId,
        Request $request,
        CoverageRepository $coverage,
        CommonsPhotoAdmission $admission,
        ContinentResolver $continents,
        WikidataImageRepository $wikidata,
        MessageBusInterface $bus,
        RateLimiterFactoryInterface $coveragePhotoLimiter,
        RateLimiterFactoryInterface $coveragePhotoFetchLimiter,
        RateLimiterFactoryInterface $coveragePhotoGlobalLimiter,
    ): Response {
        if (null !== ($limited = $this->rateLimited($request, $coveragePhotoLimiter))) {
            return $limited;
        }

        $detail = $coverage->detail($osmType, $osmId);
        if (null === $detail) {
            throw $this->createNotFoundException('No coverage POI.');
        }

        /** @var array<string, mixed> $tags */
        $tags = (array) $detail['tags'];
        $file = CommonsFile::fromTags($tags);

        // Resolve the continent BEFORE claiming. Claiming first would leave a
        // row `pending` with no job behind it whenever the continent does not
        // resolve, and a later visit from a POI that DOES resolve would find
        // that row already claimed, dispatch nothing, and spin until the poll
        // gives up. Nothing would ever fetch it again.
        /** @var array{0: float, 1: float} $ll */
        $ll = $detail['ll'];
        $continent = $continents->resolve($ll[0], $ll[1]);
        if (null === $continent) {
            // No continent means no bucket, and photo-uploads.md §2 refuses
            // rather than borrowing another continent's. Say `none` rather than
            // spin: nothing is coming.
            return $this->noStore(['state' => 'none']);
        }

        // Second hop. Only about one scenic POI in 400 names a Commons file in
        // its own tags, but 12% carry a Wikidata id and a third of those have a
        // P18 (measured 2026-08-24). Asking Wikidata is therefore where nearly
        // all the photos come from, and the answer is cached either way: an
        // item with no image is a fact, not a gap.
        if (null === $file) {
            $qidRaw = $tags['wikidata'] ?? null;
            if (!\is_string($qidRaw) || 1 !== preg_match('~^Q\d+$~', $qidRaw)) {
                return $this->noStore(['state' => 'none']);
            }
            $known = $wikidata->find($qidRaw);
            if (null === $known || !$known['answered']) {
                // A Wikidata lookup is an outbound request too, so it spends
                // the same admission budget as a Commons fetch.
                if (!$this->fetchBudgetAllows($request, $coveragePhotoFetchLimiter, $coveragePhotoGlobalLimiter)) {
                    return $this->noStore(['state' => 'none']);
                }
                if ($wikidata->claim($qidRaw)) {
                    $bus->dispatch(new ResolveWikidataImage($qidRaw, $continent));
                }

                return $this->noStore(['state' => 'pending']);
            }
            $file = $known['file'];   // answered, and null means there is no image
            if (null === $file) {
                return $this->noStore(['state' => 'none']);
            }
        }

        return $this->noStore($admission->stateFor($file, $continent,
            fn (): bool => $this->fetchBudgetAllows($request, $coveragePhotoFetchLimiter, $coveragePhotoGlobalLimiter)));
    }

    /** @param array<string, mixed> $payload */
    private function noStore(array $payload): JsonResponse
    {
        $response = new JsonResponse($payload);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }

    /**
     * Scope params: garbage/`rids[]` degrade to Everywhere, never a 400.
     *
     * @see docs/specs/map-and-search.md §4.5
     *
     * @return array{0: list<int>, 1: ?string}
     */
    private function scopeParams(Request $request): array
    {
        $query = $request->query->all();
        $ridsRaw = \is_string($query['rids'] ?? null) ? $query['rids'] : '';

        $rids = [];
        foreach (explode(',', $ridsRaw) as $part) {
            $part = trim($part);
            if ('' === $part || !ctype_digit($part)) {
                continue;
            }
            $n = (int) $part;
            if ($n >= 1 && (string) $n === ltrim($part, '0')) {
                $rids[$n] = $n;
            }
        }
        $rids = array_values($rids);
        sort($rids);
        if (\count($rids) > self::MAX_SCOPE_REGIONS) {
            $this->logger->warning('Coverage scope rids capped at {cap} (received {count}); relying on the cc arm for completeness.', ['cap' => self::MAX_SCOPE_REGIONS, 'count' => \count($rids)]);
            $rids = \array_slice($rids, 0, self::MAX_SCOPE_REGIONS);
        }

        $cc = $query['cc'] ?? null;
        $cc = (\is_string($cc) && 1 === preg_match('/^[A-Za-z]{2}$/D', $cc)) ? strtoupper($cc) : null;

        return [$rids, $cc];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function cacheable(Request $request, array $payload, int $maxAge): Response
    {
        $json = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        $response->setEtag(md5($json));
        $response->setPublic();
        // docs/specs/account-and-auth.md §5 — public cache; do not let a session cookie downgrade it.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setMaxAge($maxAge);
        $response->isNotModified($request);

        return $response;
    }
}
