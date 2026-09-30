<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Controller;

use App\Account\StaleNearby;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemType;
use App\Catalog\SubmissionStatus;
use App\Contribution\SubmissionChangeSummary;
use App\Entity\User;
use App\Moderation\AlreadyDecidedException;
use App\Moderation\ModerationService;
use App\Moderation\NotTheSubmitterException;
use App\Moderation\RetentionService;
use App\Pagination\Pager;
use App\Pagination\PageSize;
use App\Routing\LocalePrefix;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Authenticated rider profile (own account shell).
 *
 * @see docs/specs/account-and-auth.md §8
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_USER')]
final class ProfileController extends AbstractController
{
    private const int PER_PAGE = 20;

    /**
     * Rider withdraws their own undecided submission. POST + CSRF.
     *
     * @see docs/specs/moderation-and-contribution.md §3.4
     */
    #[Route('/account/contributions/withdraw/{id}', name: 'profile_withdraw', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function withdraw(int $id, Request $request, ModerationService $moderation): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('withdraw'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $moderation->withdraw($id, $user);
            $this->addFlash('success', 'flash.withdrawn');
        } catch (NotTheSubmitterException) {
            throw $this->createAccessDeniedException('Not yours to withdraw.');
        } catch (AlreadyDecidedException) {
            $this->addFlash('notice', 'flash.withdraw_too_late');
        }

        $params = array_filter([
            'letter' => $request->request->getString('letter'),
            'status' => $request->request->getString('status'),
        ], static fn (string $v): bool => '' !== $v);

        return $this->redirectToRoute('profile', $params, Response::HTTP_SEE_OTHER);
    }

    #[Route('/account/contributions', name: 'profile')]
    public function show(
        Request $request,
        EntityManagerInterface $em,
        RetentionService $retention,
        Connection $db,
        SubmissionChangeSummary $changes,
        PageSize $pageSize,
        StaleNearby $staleNearby,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $userId = (int) $user->getId();

        $letterFilter = strtoupper(trim($request->query->getString('letter')));
        if (!\in_array($letterFilter, array_map(static fn (ItemType $t): string => $t->letter(), ItemType::cases()), true)) {
            $letterFilter = '';
        }
        $statusFilter = 'withdrawn' === $request->query->getString('status') ? 'withdrawn' : '';

        /** @var list<string> $letters */
        $letters = $db->fetchFirstColumn(
            'SELECT DISTINCT letter FROM submission WHERE user_id = :uid ORDER BY letter',
            ['uid' => $userId],
        );

        // Route proposals are contributions under their own chip, letter R:
        // listed under All and under Routes, hidden by another kind's chip and
        // by the withdrawn view (a proposal has no withdrawn state).
        $routeCount = (int) $em->getRepository(RecommendedRoute::class)->count(['proposedBy' => $userId]);
        $showRoutes = \in_array($letterFilter, ['', ItemType::QualityRides->letter()], true) && '' === $statusFilter;

        [$pager, $entries] = $this->contributionsPage(
            $em,
            $db,
            $userId,
            $retention->cutoff(),
            $letterFilter,
            $statusFilter,
            $showRoutes,
            $request->query->getInt('page', 1),
            $pageSize->resolve(self::PER_PAGE),
        );
        $contributions = array_values(array_filter(array_map(
            static fn (array $e): ?Submission => $e['sub'] ?? null,
            $entries,
        )));

        $regionIds = array_values(array_unique(array_filter(array_map(
            static fn (array $e): ?int => ($e['sub'] ?? $e['route'])?->getRegionId(),
            $entries,
        ))));
        $regionNames = [] === $regionIds ? [] : $db->fetchAllKeyValue(
            'SELECT id, name FROM region WHERE id IN (:ids)',
            ['ids' => $regionIds],
            ['ids' => ArrayParameterType::INTEGER],
        );

        return $this->render('profile/show.html.twig', [
            'page_title' => 'meta.profile_title',
            'page_description' => 'meta.profile_description',
            'nav_active' => '',
            'cc_user' => $user,
            'entries' => $entries,
            'pager' => $pager,
            'letter_chips' => $this->letterChips($letters, $routeCount > 0),
            // Letter → label key for every type, so a card can name what kind
            // of place it is about, not only "New item" (owner 2026-08-25).
            'letter_labels' => array_combine(
                array_map(static fn (ItemType $t): string => $t->letter(), ItemType::cases()),
                array_map(static fn (ItemType $t): string => $t->labelKey(), ItemType::cases()),
            ),
            'letter_filter' => $letterFilter,
            'status_filter' => $statusFilter,
            'region_names' => $regionNames,
            'submission_threads' => $this->threadsFor((int) $user->getId(), $contributions, $db),
            'submission_changes' => array_reduce(
                $contributions,
                static function (array $carry, Submission $s) use ($changes): array {
                    $rows = $changes->rows($s);
                    if ([] !== $rows) {
                        $carry[$s->getId()] = $rows;
                    }

                    return $carry;
                },
                [],
            ),
            // docs/specs/route-domain.md §6 — ballots private to the voter.
            'votes' => $db->fetchAllAssociative(
                'SELECT rv.season, rv.bike_type, rv.created_at, rr.id AS route_id, rr.name
                   FROM route_vote rv JOIN recommended_route rr ON rr.id = rv.route_id
                  WHERE rv.user_id = :uid
                  ORDER BY rv.created_at DESC, rv.id DESC
                  LIMIT 50',
                ['uid' => $userId],
            ),
            'confirmations' => $this->confirmations($db, $userId),
            'stale_nearby' => $staleNearby->for($user, 12),
            'curator_applications' => $db->fetchAllAssociative(
                'SELECT ca.country_code, ca.status, ca.created_at, ca.decision_note, r.slug AS region_slug
                   FROM curator_application ca
                   LEFT JOIN region r ON r.id = ca.requested_region_id
                  WHERE ca.user_id = :uid
                  ORDER BY ca.created_at DESC, ca.id DESC',
                ['uid' => $userId],
            ),
            'curating' => $this->curatingContext($db, $user),
        ]);
    }

    /**
     * One page of the rider's contributions: place submissions and route
     * proposals in one list, newest first, paged in SQL over the union of
     * both so a route never sits behind a page of places.
     *
     * Submissions follow the retention rule (moderation-and-contribution.md
     * §8): a rejected or withdrawn row past the cutoff is hidden even before
     * the sweep deletes it. Route proposals join only when `$withRoutes`.
     *
     * @return array{0: array{page: int, pages: int, total: int, perPage: int, offset: int, prev: int|null, next: int|null}, 1: list<array{sub: Submission|null, route: RecommendedRoute|null}>}
     *
     * @see docs/specs/account-and-auth.md (Contributions pane)
     */
    private function contributionsPage(
        EntityManagerInterface $em,
        Connection $db,
        int $userId,
        \DateTimeImmutable $cutoff,
        string $letterFilter,
        string $statusFilter,
        bool $withRoutes,
        int $page,
        int $perPage,
    ): array {
        $params = [
            'uid' => $userId,
            'swept' => [SubmissionStatus::Rejected->value, SubmissionStatus::Withdrawn->value],
            'cutoff' => $cutoff->format('Y-m-d H:i:s'),
        ];
        $types = ['swept' => ArrayParameterType::STRING];
        $where = 's.user_id = :uid AND (s.status NOT IN (:swept) OR s.decided_at IS NULL OR s.decided_at >= :cutoff)';
        if ('' !== $letterFilter) {
            $where .= ' AND s.letter = :letter';
            $params['letter'] = $letterFilter;
        }
        if ('' !== $statusFilter) {
            $where .= ' AND s.status = :status';
            $params['status'] = SubmissionStatus::Withdrawn->value;
        }
        $union = "SELECT 'sub' AS kind, s.id, s.created_at FROM submission s WHERE ".$where;
        if ($withRoutes) {
            $union .= " UNION ALL SELECT 'route' AS kind, r.id, r.created_at FROM recommended_route r WHERE r.proposed_by = :uid";
        }

        $pager = Pager::of($page, (int) $db->fetchOne('SELECT COUNT(*) FROM ('.$union.') c', $params, $types), $perPage);

        /** @var list<array{kind: string, id: int|string}> $rows */
        $rows = $db->fetchAllAssociative(
            'SELECT kind, id FROM ('.$union.') c ORDER BY created_at DESC, kind, id DESC LIMIT :limit OFFSET :offset',
            [...$params, 'limit' => $pager['perPage'], 'offset' => $pager['offset']],
            [...$types, 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        $ids = ['sub' => [], 'route' => []];
        foreach ($rows as $row) {
            $ids['sub' === $row['kind'] ? 'sub' : 'route'][] = (int) $row['id'];
        }
        $subs = [];
        foreach ([] === $ids['sub'] ? [] : $em->getRepository(Submission::class)->findBy(['id' => $ids['sub']]) as $s) {
            $subs[(int) $s->getId()] = $s;
        }
        $routes = [];
        foreach ([] === $ids['route'] ? [] : $em->getRepository(RecommendedRoute::class)->findBy(['id' => $ids['route']]) as $r) {
            $routes[(int) $r->getId()] = $r;
        }

        $entries = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $entry = 'sub' === $row['kind']
                ? ['sub' => $subs[$id] ?? null, 'route' => null]
                : ['sub' => null, 'route' => $routes[$id] ?? null];
            if (null !== $entry['sub'] || null !== $entry['route']) {
                $entries[] = $entry;
            }
        }

        return [$pager, $entries];
    }

    /**
     * The kind chips over the Contributions list: Routes first (the rider's
     * route proposals and any route submission share letter R), then one chip
     * per catalog type the rider has a submission of.
     *
     * @param list<string> $letters letters of the rider's submissions
     *
     * @return list<array{letter: string, labelKey: string}>
     */
    private function letterChips(array $letters, bool $hasRouteProposals): array
    {
        $routes = ItemType::QualityRides->letter();
        $chips = $hasRouteProposals || \in_array($routes, $letters, true)
            ? [['letter' => $routes, 'labelKey' => 'nav.routes']]
            : [];
        foreach (ItemType::placeKinds() as $t) {
            if (\in_array($t->letter(), $letters, true)) {
                $chips[] = ['letter' => $t->letter(), 'labelKey' => $t->labelKey()];
            }
        }

        return $chips;
    }

    /**
     * Message thread per submission. `sender_id = :uid` finds the rider's reply (addressed to the curator).
     *
     * @param list<Submission> $contributions
     *
     * @return array<int, array{askedId: ?int, reply: ?string}>
     */
    private function threadsFor(int $userId, array $contributions, Connection $db): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (Submission $s): ?int => $s->getId(),
            $contributions,
        )));
        if ([] === $ids) {
            return [];
        }

        $rows = $db->fetchAllAssociative(
            "SELECT id, ref_id, kind, sender, body_text
               FROM user_message
              WHERE channel = 'submission' AND ref_id IN (:ids)
                AND (user_id = :uid OR sender_id = :uid)
              ORDER BY id ASC",
            ['ids' => $ids, 'uid' => $userId],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $threads = [];
        foreach ($rows as $row) {
            $ref = (int) $row['ref_id'];
            $threads[$ref] ??= ['askedId' => null, 'reply' => null];
            if ('submission_needs_info' === $row['kind']) {
                $threads[$ref]['askedId'] = (int) $row['id'];
            }
            if ('rider' === $row['sender']) {
                $threads[$ref]['reply'] = null !== $row['body_text'] ? (string) $row['body_text'] : null;
            }
        }

        return $threads;
    }

    /**
     * Curator cover where the rider is: region and country reported separately.
     *
     * @see docs/specs/account-and-auth.md (The curating invitation on the landing pane)
     *
     * @return array{state: string, slug: string, country: string}
     */
    private function curatingContext(Connection $db, User $user): array
    {
        $ids = $user->getBaseRegionIds();
        if ([] !== $ids) {
            // docs/specs/map-and-search.md §4.5 — first id contains the base point.
            $row = $db->fetchAssociative(
                'SELECT r.slug,
                        EXISTS (SELECT 1 FROM moderator_area ma WHERE ma.region_id = r.id)                AS local,
                        EXISTS (SELECT 1 FROM moderator_area ma WHERE ma.country_code = r.country_code)   AS national
                   FROM region r WHERE r.id = :id',
                ['id' => $ids[0]],
            );
            if (false !== $row) {
                $state = match (true) {
                    (bool) $row['local'] => 'region_local',
                    (bool) $row['national'] => 'region_national',
                    default => 'region_none',
                };

                return ['state' => $state, 'slug' => (string) $row['slug'], 'country' => ''];
            }
        }

        $country = $user->getCountry();
        if (null !== $country) {
            $covered = (bool) $db->fetchOne(
                'SELECT EXISTS (SELECT 1 FROM moderator_area WHERE country_code = :cc)
                     OR EXISTS (SELECT 1 FROM moderator_area ma
                                  JOIN region r ON r.id = ma.region_id
                                 WHERE r.country_code = :cc)',
                ['cc' => $country->getIso2()],
            );

            return [
                'state' => $covered ? 'country_some' : 'country_none',
                'slug' => '',
                'country' => $country->getName(),
            ];
        }

        return ['state' => 'unknown', 'slug' => '', 'country' => ''];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function confirmations(Connection $db, int $userId): array
    {
        $rows = $db->fetchAllAssociative(
            'SELECT ic.stance, ic.created_at, i.id AS item_id, i.name, i.letter
               FROM item_confirmation ic JOIN item i ON i.id = ic.item_id
              WHERE ic.user_id = :uid
              ORDER BY ic.created_at DESC, ic.id DESC
              LIMIT 50',
            ['uid' => $userId],
        );
        foreach ($rows as &$row) {
            $row['typeLabelKey'] = ItemType::fromParam((string) $row['letter'])->labelKey();
        }

        return $rows;
    }
}
