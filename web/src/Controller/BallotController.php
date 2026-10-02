<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\BestOfPreview;
use App\Catalog\BikeType;
use App\Catalog\ItemType;
use App\Entity\User;
use App\Routing\LocalePrefix;
use App\Routing\LocalizedPath;
use App\Vote\BallotCandidates;
use App\Vote\BallotRefused;
use App\Vote\BallotRegions;
use App\Vote\BallotRules;
use App\Vote\BallotService;
use App\Vote\Hemisphere;
use App\Vote\ListKey;
use App\Vote\Round;
use App\Vote\SeasonResults;
use App\Vote\VoterEligibility;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The season ballot: three votes per list, for one region and one kind of
 * place.
 *
 * Server-rendered forms and no script: a vote is a POST that comes back to
 * the list it was cast in. The page is per rider, so it is never in the
 * shared page cache (page-caching.md §6).
 *
 * @phpstan-import-type RegionRow from BallotRegions
 * @phpstan-import-type Subject from BallotCandidates
 *
 * @see docs/specs/route-domain.md §8c, §8d
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
final class BallotController extends AbstractController
{
    private const string CSRF_ID = 'season-ballot';

    public function __construct(
        private readonly BallotService $ballots,
        private readonly BallotCandidates $candidates,
        private readonly BallotRegions $regions,
        private readonly VoterEligibility $eligibility,
        private readonly SeasonResults $results,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(LocalizedPath::VOTE, name: 'vote', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function ballot(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $bag = $request->isMethod('POST') ? $request->request : $request->query;
        $type = self::category($bag->all()['cat'] ?? null);

        if ($request->isMethod('POST')) {
            return $this->handle($request, $user, $type);
        }

        $regions = $this->regions->byCountry($request->getLocale());
        $pick = self::positiveInt($request->query->all()['pick'] ?? null);
        $subject = null !== $pick ? $this->candidates->subject($type, $pick) : null;
        $slug = $request->query->all()['region'] ?? '';
        $region = self::chosenRegion($regions, \is_string($slug) ? $slug : '', $subject, $user);

        $view = [
            'page_title' => 'meta.vote_title',
            'page_description' => 'meta.vote_description',
            'nav_active' => 'vote',
            'open' => $this->ballots->isOpen(),
            'missing' => $this->eligibility->missing($user),
            'votes_from' => $this->eligibility->votesFrom($user),
            'min_age_days' => BallotRules::MIN_ACCOUNT_AGE_DAYS,
            'categories' => BestOfPreview::categories(),
            'category' => $type,
            'regions' => $regions,
            'region' => $region,
            'region_country' => null !== $region ? self::countryOf($regions, $region['id']) : null,
            'bikes' => BikeType::cases(),
            'csrf_id' => self::CSRF_ID,
            'per_list' => BallotRules::VOTES_PER_LIST,
            'threshold' => BallotRules::RANKING_THRESHOLD,
            'round' => null,
            'candidates' => [],
            'mine' => [],
            'ballot' => [],
            'pick' => null,
            'voters' => 0,
        ];

        if (null !== $region) {
            $round = Round::containing($this->clock->now(), Hemisphere::ofLatitude($region['mid']));
            $mine = [];
            foreach ($this->ballots->mine($user, $type, $region['id'], $round) as $id => $bike) {
                $mine[$id] = null !== $bike ? BikeType::tryFrom($bike)?->labelKey() : null;
            }
            $candidates = $this->candidates->top($type, $region['id'], BallotRules::BALLOT_CANDIDATES);

            // The pick and the rider's own votes are on the page however far down the list they sit.
            $wanted = array_keys($mine);
            if (null !== $subject && $subject['regionId'] === $region['id']) {
                $wanted[] = $subject['id'];
                $view['pick'] = $subject['id'];
            }
            $extra = array_values(array_diff(array_unique($wanted), array_column($candidates, 'id')));
            if ([] !== $extra) {
                $names = $this->candidates->names($type, $extra);
                $counts = $this->candidates->confirmations($type, $extra);
                foreach (array_reverse($extra) as $id) {
                    array_unshift($candidates, ['id' => $id, 'name' => $names[$id] ?? '', 'confirmations' => $counts[$id] ?? 0]);
                }
            }

            $nameOf = array_column($candidates, 'name', 'id');
            $ballot = [];
            foreach ($mine as $id => $bikeKey) {
                $ballot[] = ['id' => $id, 'name' => $nameOf[$id] ?? '', 'bike' => $bikeKey];
            }

            $view['round'] = $round;
            $view['candidates'] = $candidates;
            $view['mine'] = $mine;
            $view['ballot'] = $ballot;
            $view['voters'] = $this->results->list(new ListKey($region['id'], $type), $round)['voters'];
        }

        return $this->render('vote/ballot.html.twig', $view);
    }

    private function handle(Request $request, User $user, ItemType $type): Response
    {
        $form = $request->request->all();
        if (!$this->isCsrfTokenValid(self::CSRF_ID, \is_string($form['_token'] ?? null) ? $form['_token'] : '')) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $id = self::positiveInt($form['id'] ?? null) ?? 0;
        try {
            if ('remove' === ($form['do'] ?? null)) {
                if ($this->ballots->remove($user, $type, $id)) {
                    $this->addFlash('success', 'vote.removed');
                }
            } else {
                $bike = $form['bike'] ?? null;
                $this->ballots->cast($user, $type, $id, \is_string($bike) ? BikeType::tryFrom($bike) : null);
                $this->addFlash('success', 'vote.saved');
            }
        } catch (BallotRefused $e) {
            $this->addFlash('error', $e->messageKey());
        }

        $back = ['cat' => $type->value];
        $region = $form['region'] ?? null;
        if (\is_string($region) && 1 === preg_match('/^[a-z0-9-]{1,100}$/', $region)) {
            $back = ['region' => $region] + $back;
        }

        return $this->redirectToRoute('vote', $back, Response::HTTP_SEE_OTHER);
    }

    /** @param array<string, list<RegionRow>> $regions */
    private static function countryOf(array $regions, int $regionId): ?string
    {
        foreach ($regions as $country => $list) {
            if (\in_array($regionId, array_column($list, 'id'), true)) {
                return (string) $country;
            }
        }

        return null;
    }

    /** A votable category; anything else, absent or unknown, opens climbs. */
    private static function category(mixed $value): ItemType
    {
        $type = \is_string($value) ? ItemType::tryFrom($value) : null;

        return null !== $type && $type->isVotable() ? $type : ItemType::Climbs;
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (\is_string($value) && 1 === preg_match('/^[1-9]\d{0,17}$/', $value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * The asked region, else the picked row's region, else the rider's first base region.
     *
     * @param array<string, list<RegionRow>> $regions
     * @param Subject|null                   $subject
     *
     * @return RegionRow|null
     */
    private static function chosenRegion(array $regions, string $slug, ?array $subject, User $user): ?array
    {
        $byId = [];
        $bySlug = [];
        foreach ($regions as $list) {
            foreach ($list as $r) {
                $byId[$r['id']] = $r;
                $bySlug[$r['slug']] = $r;
            }
        }
        if ('' !== $slug && isset($bySlug[$slug])) {
            return $bySlug[$slug];
        }
        if (null !== $subject && isset($byId[$subject['regionId']])) {
            return $byId[$subject['regionId']];
        }
        foreach ($user->getBaseRegionIds() as $id) {
            if (isset($byId[$id])) {
                return $byId[$id];
            }
        }

        return null;
    }
}
