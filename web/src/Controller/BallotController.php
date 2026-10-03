<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\BestOfPreview;
use App\Catalog\BikeType;
use App\Catalog\ItemType;
use App\Catalog\StayKind;
use App\Entity\User;
use App\Routing\LocalePrefix;
use App\Routing\LocalizedPath;
use App\Vote\BallotCandidates;
use App\Vote\BallotRefused;
use App\Vote\BallotRegions;
use App\Vote\BallotRules;
use App\Vote\BallotService;
use App\Vote\Countdown;
use App\Vote\Hemisphere;
use App\Vote\Round;
use App\Vote\VoterEligibility;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The season ballot: a ranked handful of votes per list (BallotRules), for
 * one region and one kind of place, cast in one season for the next one's
 * list. The page shows the rider's own ballot and no count of anyone's votes.
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
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        private readonly BestOfPreview $cards,
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
            'route_bikes' => [],
            'csrf_id' => self::CSRF_ID,
            'per_list' => BallotRules::VOTES_PER_LIST,
            'points' => BallotRules::POINTS_BY_SLOT,
            'round' => null,
            'countdown' => null,
            'candidates' => [],
            'cards' => [],
            'stay_kinds' => [],
            'stay_paths' => StayKind::PATHS,
            'mine' => [],
            'ballot' => [],
            'pick' => null,
            'submitted_at' => null,
            'submitted_categories' => [],
        ];

        if (null !== $region) {
            // The ballot open now fills next season's list (route-domain.md §8c).
            $round = Round::votingAt($this->clock->now(), Hemisphere::ofLatitude($region['mid']));
            $mine = [];
            foreach ($this->ballots->mine($user, $type, $region['id'], $round) as $id => $bike) {
                $mine[$id] = null !== $bike ? BikeType::tryFrom($bike)?->labelKey() : null;
            }
            // A to Z: the order on the ballot never favours a place (owner 2026-10-03).
            $candidates = $this->candidates->alphabetical($type, $region['id'], BallotRules::BALLOT_CANDIDATES);

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
                $rank = \count($ballot) + 1;
                $ballot[] = ['id' => $id, 'name' => $nameOf[$id] ?? '', 'bike' => $bikeKey, 'rank' => $rank, 'points' => BallotRules::POINTS_BY_SLOT[$rank] ?? 0];
            }

            $view['round'] = $round;
            $view['countdown'] = Countdown::of($this->clock->now(), $round->votingClosesAt());
            $view['candidates'] = $candidates;
            // A picture and one line about each, the /best cards (owner 2026-10-03: "more info, photo if available").
            $view['cards'] = $this->cards->cards($type, array_column($candidates, 'id'));
            // A place to sleep without a picture shows its kind: hotel, house, tent, bunk, cabin.
            if (ItemType::WhereToSleep === $type) {
                $view['stay_kinds'] = $this->candidates->stayKinds(array_column($candidates, 'id'));
            }
            if (ItemType::QualityRides === $type) {
                $view['route_bikes'] = $this->candidates->bikesFor(array_column($candidates, 'id'));
            }
            $view['mine'] = $mine;
            $view['ballot'] = $ballot;
            $view['submitted_at'] = $this->ballots->submittedAt($user, $type, $region['id'], $round);
            $view['submitted_categories'] = $this->ballots->submittedCategories($user, $region['id'], $round);
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
            $do = $form['do'] ?? null;
            if ('up' === $do || 'down' === $do) {
                $moved = $this->ballots->move($user, $type, $id, 'up' === $do);
                // Reordering is a background call from the page (ballot.js): no reload.
                if (self::wantsJson($request)) {
                    return new JsonResponse(['moved' => $moved]);
                }
            } elseif ('submit' === $do) {
                $regionId = self::regionIdOf($this->regions->byCountry($request->getLocale()), \is_string($form['region'] ?? null) ? $form['region'] : '');
                if (null === $regionId) {
                    throw new BallotRefused(BallotRefused::BALLOT_INCOMPLETE);
                }
                $this->ballots->submit($user, $type, $regionId);
                $this->addFlash('success', 'vote.submitted_flash');
            } elseif ('remove' === $do) {
                if ($this->ballots->remove($user, $type, $id)) {
                    $this->addFlash('success', 'vote.removed');
                }
            } else {
                $bike = $form['bike'] ?? null;
                $this->ballots->cast($user, $type, $id, \is_string($bike) ? BikeType::tryFrom($bike) : null);
                // No "your vote is in" line: it read as if voting were done. The
                // ballot shows the vote, and the row just added fades in there
                // (owner 2026-10-03). One page view only, like any flash.
                $this->addFlash('vote_added', (string) $id);
            }
        } catch (BallotRefused $e) {
            if (self::wantsJson($request)) {
                return new JsonResponse(['moved' => false, 'error' => $this->translator->trans($e->messageKey())], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash('error', $e->messageKey());
        }

        $back = ['cat' => $type->value];
        $region = $form['region'] ?? null;
        if (\is_string($region) && 1 === preg_match('/^[a-z0-9-]{1,100}$/', $region)) {
            $back = ['region' => $region] + $back;
        }

        return $this->redirectToRoute('vote', $back, Response::HTTP_SEE_OTHER);
    }

    private static function wantsJson(Request $request): bool
    {
        return \in_array('application/json', $request->getAcceptableContentTypes(), true);
    }

    /** @param array<string, list<RegionRow>> $regions */
    private static function regionIdOf(array $regions, string $slug): ?int
    {
        foreach ($regions as $list) {
            foreach ($list as $r) {
                if ($r['slug'] === $slug) {
                    return $r['id'];
                }
            }
        }

        return null;
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
