<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Media\Commons\CommonsPhotoAdmission;
use App\Media\Commons\WikidataImageRepository;
use App\Media\ContinentResolver;
use App\Media\Message\ResolveWikidataImage;
use App\Town\Message\ResolveTownSummary;
use App\Town\MessageHandler\ResolveTownSummaryHandler;
use App\Town\OsmElementApi;
use App\Town\TownSummaryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The town card's fetched knowledge, polled the way the coverage photo is:
 * the first reader of a town claims the row and queues the lookup; everyone
 * after reads the cache.
 *
 * The client names an OpenStreetMap element and a language, never a page or a
 * Wikidata id, so nobody can hand us an arbitrary URL to fetch.
 *
 * @see docs/specs/map-and-search.md §6.5
 *
 * @api
 */
final class TownController extends AbstractController
{
    use ThirdPartyBudget;

    /**
     * Never cached: it changes while the reader watches the spinner.
     */
    #[Route('/map/town/{osmType}/{osmId}', name: 'map_town', requirements: ['osmType' => 'node|way|relation', 'osmId' => '\d+'], methods: ['GET'])]
    public function summary(
        string $osmType,
        int $osmId,
        Request $request,
        TownSummaryRepository $towns,
        WikidataImageRepository $wikidata,
        CommonsPhotoAdmission $admission,
        ContinentResolver $continents,
        MessageBusInterface $bus,
        RateLimiterFactoryInterface $coveragePhotoLimiter,
        RateLimiterFactoryInterface $coveragePhotoFetchLimiter,
        RateLimiterFactoryInterface $coveragePhotoGlobalLimiter,
    ): Response {
        if (null !== ($limited = $this->rateLimited($request, $coveragePhotoLimiter))) {
            return $limited;
        }
        if (!\in_array($osmType, OsmElementApi::TYPES, true)) {
            throw $this->createNotFoundException();
        }
        $ref = $osmType.'/'.$osmId;
        $lang = $this->lang($request);
        $continent = $this->continent($request, $continents);
        $budget = fn (): bool => $this->fetchBudgetAllows($request, $coveragePhotoFetchLimiter, $coveragePhotoGlobalLimiter);

        $row = $towns->find($ref, $lang);
        if (null === $row) {
            // Three outbound requests follow, so this spends the same
            // admission budget a Commons fetch does.
            if (!$budget()) {
                return $this->noStore(['state' => 'none']);
            }
            if ($towns->claim($ref, $lang)) {
                $bus->dispatch(new ResolveTownSummary($ref, $lang, $continent));
            }

            return $this->noStore(['state' => 'pending']);
        }
        if (!$row['answered']) {
            return $this->noStore(['state' => 'pending']);
        }

        $text = null === $row['title'] || null === $row['extract'] || null === $row['page_url'] ? null : [
            'title' => $row['title'],
            'extract' => $row['extract'],
            'url' => $row['page_url'],
            'lang' => $row['page_lang'] ?? 'en',
            'license' => 'CC BY-SA 4.0',
        ];

        return $this->noStore([
            'state' => 'ready',
            'text' => $text,
            'cycling' => $row['cycling'],
            'facts' => (object) $row['facts'],
            'edited' => $row['edited'],
            'photo' => $this->photo($row['qid'], $continent, $wikidata, $admission, $bus, $budget),
        ]);
    }

    /**
     * The same P18 path the coverage photo walks, keyed by the town's Wikidata id.
     *
     * @param callable(): bool $budget
     *
     * @return array<string, mixed>
     */
    private function photo(?string $qid, ?string $continent, WikidataImageRepository $wikidata, CommonsPhotoAdmission $admission, MessageBusInterface $bus, callable $budget): array
    {
        if (null === $qid || null === $continent) {
            return ['state' => 'none'];
        }
        $known = $wikidata->find($qid);
        if (null === $known || !$known['answered']) {
            if (null === $known) {
                if (!$budget()) {
                    return ['state' => 'none'];
                }
                if ($wikidata->claim($qid)) {
                    $bus->dispatch(new ResolveWikidataImage($qid, $continent));
                }
            }

            return ['state' => 'pending'];
        }
        if (null === $known['file']) {
            return ['state' => 'none'];
        }

        return $admission->stateFor($known['file'], $continent, $budget);
    }

    /** The reader's language when we have that Wikipedia, else English. */
    private function lang(Request $request): string
    {
        $raw = $request->query->get('lang');
        $lang = \is_string($raw) ? strtolower(substr($raw, 0, 2)) : substr($request->getLocale(), 0, 2);

        return \in_array($lang, ResolveTownSummaryHandler::LANGS, true) ? $lang : 'en';
    }

    /** Which bucket a photo would land in; null when the coordinates are missing or resolve nowhere. */
    private function continent(Request $request, ContinentResolver $continents): ?string
    {
        $lat = $request->query->get('lat');
        $lng = $request->query->get('lng');
        if (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90.0 || abs((float) $lng) > 180.0) {
            return null;
        }

        return $continents->resolve((float) $lat, (float) $lng);
    }

    /** @param array<string, mixed> $payload */
    private function noStore(array $payload): JsonResponse
    {
        $response = new JsonResponse($payload);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
