<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use App\Catalog\ConfirmationFreshness;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemState;
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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Renders the authenticated user's public-facing profile page.
 *
 * @api Instantiated by Symfony's router — `@api` tells Psalm this is a live
 *      entry point, not dead code.
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_USER')]
final class ProfileController extends AbstractController
{
    /**
     * Contributions and route proposals per page. Both lists used to stop dead
     * at 50 rows with nothing on the page saying so, which is the failure mode
     * a rider notices only by missing something (2026-08-08). They page
     * independently - `?page=` and `?rpage=` - because they sit on one pane and
     * a shared parameter would move both when a rider only meant to move one.
     */
    private const int PER_PAGE = 20;

    /**
     * A rider takes back their own undecided submission (owner 2026-08-16).
     * POST + CSRF; the service enforces ownership and the undecided state, so
     * a forged or stale form ends in a flash, never a half-withdrawal.
     */
    #[Route('/profile/withdraw/{id}', name: 'profile_withdraw', requirements: ['id' => '\d+'], methods: ['POST'])]
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
            // A curator got there first - the list the rider lands back on
            // shows the real outcome, which says more than any error could.
            $this->addFlash('notice', 'flash.withdraw_too_late');
        }

        // Land back on the SAME filtered view (owner 2026-08-16: withdrawing
        // used to drop the selected category). The form posts the active
        // filters along; show() re-validates them, so garbage just falls
        // back to unfiltered.
        $params = array_filter([
            'letter' => $request->request->getString('letter'),
            'status' => $request->request->getString('status'),
        ], static fn (string $v): bool => '' !== $v);

        return $this->redirectToRoute('profile', $params, Response::HTTP_SEE_OTHER);
    }

    #[Route('/profile', name: 'profile')]
    public function show(
        Request $request,
        EntityManagerInterface $em,
        RetentionService $retention,
        Connection $db,
        SubmissionChangeSummary $changes,
        PageSize $pageSize,
        ConfirmationFreshness $freshness,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $userId = (int) $user->getId();

        // Category filter (owner 2026-08-16: "only the roads or scenic views
        // I added"). Chips render only for letters this rider actually has,
        // so the guard just has to keep garbage out of the query.
        $letterFilter = strtoupper(trim($request->query->getString('letter')));
        if (!\in_array($letterFilter, array_map(static fn (ItemType $t): string => $t->letter(), ItemType::cases()), true)) {
            $letterFilter = '';
        }
        // The withdrawn toggle combines with the category chips (owner
        // 2026-08-16): both are plain GET params, ANDed in the query.
        $statusFilter = 'withdrawn' === $request->query->getString('status') ? 'withdrawn' : '';

        // Lazy retention filter (correct even if no sweep has run yet, M8
        // phase 1): a rejected submission past the cutoff must never render
        // here, whether or not RetentionService::sweep() has deleted it. The
        // count applies the SAME filter as the page query - a total that counts
        // rows the list refuses to show would page into empty tails.
        $contributionsQuery = static function (EntityManagerInterface $em) use ($userId, $retention, $letterFilter, $statusFilter) {
            $qb = $em->createQueryBuilder()
                ->from(Submission::class, 's')
                ->where('s.userId = :uid')
                ->andWhere('(s.status NOT IN (:swept) OR s.decidedAt IS NULL OR s.decidedAt >= :cutoff)')
                ->setParameter('uid', $userId)
                // Withdrawn rides the same retention clock as rejected (the sweep
                // deletes both), so the lazy filter must hide both past the cutoff.
                ->setParameter('swept', [SubmissionStatus::Rejected, SubmissionStatus::Withdrawn])
                ->setParameter('cutoff', $retention->cutoff());
            if ('' !== $letterFilter) {
                $qb->andWhere('s.letter = :letter')->setParameter('letter', $letterFilter);
            }
            if ('' !== $statusFilter) {
                $qb->andWhere('s.status = :status')->setParameter('status', SubmissionStatus::Withdrawn);
            }

            return $qb;
        };

        // The chips: which categories this rider has contributed to at all
        // (unfiltered - the filter must not hide its own alternatives).
        /** @var list<string> $letters */
        $letters = $db->fetchFirstColumn(
            'SELECT DISTINCT letter FROM submission WHERE user_id = :uid ORDER BY letter',
            ['uid' => $userId],
        );

        $pager = Pager::of(
            $request->query->getInt('page', 1),
            (int) $contributionsQuery($em)->select('COUNT(s.id)')->getQuery()->getSingleScalarResult(),
            $pageSize->resolve(self::PER_PAGE),
        );

        /** @var list<Submission> $contributions */
        $contributions = $contributionsQuery($em)
            ->select('s')
            ->orderBy('s.createdAt', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setFirstResult($pager['offset'])
            ->setMaxResults($pager['perPage'])
            ->getQuery()
            ->getResult();

        /* WHERE each contribution is, as words (owner 2026-08-16: Scout rows
           all read "Scenery" and nothing else). The region stamp is already
           on the row; names only, one query for the page - never the Region
           entity, whose geometry is the moderator-areas OOM lesson. */
        $regionIds = array_values(array_unique(array_filter(array_map(
            static fn (Submission $s): ?int => $s->getRegionId(),
            $contributions,
        ))));
        $regionNames = [] === $regionIds ? [] : $db->fetchAllKeyValue(
            'SELECT id, name FROM region WHERE id IN (:ids)',
            ['ids' => $regionIds],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $routePager = Pager::of(
            $request->query->getInt('rpage', 1),
            (int) $em->getRepository(RecommendedRoute::class)->count(['proposedBy' => $userId]),
            $pageSize->resolve(self::PER_PAGE),
        );

        return $this->render('profile/show.html.twig', [
            'page_title' => 'meta.profile_title',
            'page_description' => 'meta.profile_description',
            'nav_active' => '',
            'cc_user' => $user,
            'contributions' => $contributions,
            'pager' => $pager,
            'route_pager' => $routePager,
            // Category chips: one per letter this rider has contributed to,
            // labelled by the item type's own key, plus the active filter.
            'letter_chips' => array_values(array_filter(array_map(
                static fn (ItemType $t): ?array => \in_array($t->letter(), $letters, true)
                    ? ['letter' => $t->letter(), 'labelKey' => $t->labelKey()]
                    : null,
                ItemType::cases(),
            ))),
            'letter_filter' => $letterFilter,
            'status_filter' => $statusFilter,
            'region_names' => $regionNames,
            // The conversation attached to each submission, so a contribution
            // row can show it the way the curator's desk shows the rider's
            // reply. Without this the rider saw a "needs info" chip and had no
            // hint that a question was waiting on the messages page, nor that
            // their own answer had been delivered.
            'submission_threads' => $this->threadsFor((int) $user->getId(), $contributions, $db),
            // WHAT each contribution actually changed. A row naming a place and
            // a verdict says nothing to the person who wrote it — two edits to
            // the same climb read identically (owner-reported 2026-08-03).
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
            'route_proposals' => $em->getRepository(RecommendedRoute::class)->findBy(
                ['proposedBy' => $userId],
                ['createdAt' => 'DESC', 'id' => 'DESC'],
                $routePager['perPage'],
                $routePager['offset'],
            ),
            // Route ballots are private to the voter (route-domain.md §6): this
            // page is the only surface that shows WHAT was voted for, and only
            // to the account that cast it.
            'votes' => $db->fetchAllAssociative(
                'SELECT rv.season, rv.bike_type, rv.created_at, rr.id AS route_id, rr.name
                   FROM route_vote rv JOIN recommended_route rr ON rr.id = rv.route_id
                  WHERE rv.user_id = :uid
                  ORDER BY rv.created_at DESC, rv.id DESC
                  LIMIT 50',
                ['uid' => $userId],
            ),
            'confirmations' => $this->confirmations($db, $userId),
            /* PLACES NEAR YOU WORTH A LOOK (owner 2026-08-12, the second half
               of the staleness item). The orange ring on the map is a passive
               colour; this is the half a rider can act on deliberately - "have
               a look at these if you are nearby".

               Only where the rider has set a base location. Without one there
               is no "near you" to answer, and a national list of stale taps is
               a chore rather than a nudge, which is the exact thing this
               feature is not supposed to become. */
            'stale_nearby' => $this->staleNearby($db, $user, $freshness),
            // The answer to "where is my curator request?" lives on the landing
            // pane.
            'curator_applications' => $db->fetchAllAssociative(
                'SELECT ca.country_code, ca.status, ca.created_at, ca.decision_note, r.slug AS region_slug
                   FROM curator_application ca
                   LEFT JOIN region r ON r.id = ca.requested_region_id
                  WHERE ca.user_id = :uid
                  ORDER BY ca.created_at DESC, ca.id DESC',
                ['uid' => $userId],
            ),
            // Whether the rider's OWN patch has anyone looking after it. Someone
            // who has never applied is not "a curator with no applications" —
            // they are a rider, and the only thing worth telling them here is
            // whether their area needs somebody.
            'curating' => $this->curatingContext($db, $user),
        ]);
    }

    /**
     * The message thread behind each of the rider's submissions.
     *
     * Both halves travel, because both are missing from a submission row
     * otherwise: `askedId` anchors the "answer this" link at the curator's
     * question on the messages page, and `reply` is the rider's own last
     * answer — the same line the curator's desk shows as "Rider replied".
     *
     * `sender_id = :uid` is what makes the rider's own reply findable at all:
     * a needs-info reply is addressed TO the deciding curator, so it never
     * appears under this rider's `user_id` (MessageService::sendRiderReply).
     *
     * One query for the page, keyed by submission id.
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
     * What to tell a rider about curation where they are.
     *
     * Best evidence first: their base region if they set a location, otherwise
     * their declared country, otherwise nothing. The country fallback matters —
     * plenty of accounts pick a country and never set a base location, and
     * "somewhere in France" is still a far better answer than a generic
     * paragraph about what a curator is.
     *
     * Region-level and country-level cover are reported SEPARATELY rather than
     * folded into one boolean. A country moderator looking after all of NL is
     * real cover, so a region under one is not "uncovered" — but it is also not
     * done: a region can have its own curators alongside the country's, and
     * somebody who actually rides there sees what a country-wide view never
     * will. Telling those two states apart is the difference between inviting
     * the right people and either nagging or ignoring them.
     *
     * @return array{state: string, slug: string, country: string}
     */
    private function curatingContext(Connection $db, User $user): array
    {
        $ids = $user->getBaseRegionIds();
        if ([] !== $ids) {
            // The first id is the region CONTAINING the base point
            // (map-and-search.md §4.5 orders them that way), not merely a nearby one.
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
     * The user's place confirmations ("still here?" / potability stances,
     * moderation-and-contribution.md §1.6) with the item-type label key
     * resolved from the catalog letter.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Stale places inside the rider's own area, soonest-forgotten first.
     *
     * Three narrowings, and each is the same one the map makes, on purpose -
     * two surfaces disagreeing about what "stale" means would be worse than
     * either being wrong:
     *  - only letters whose confirmations go off ({@see ItemType::confirmationAges()});
     *  - only items somebody has actually confirmed, because a never-confirmed
     *    item is unverified rather than stale;
     *  - only `source <> 'form'` confirmations, so a submitter answering their
     *    own improve form cannot keep their own pin off this list.
     *
     * The cut is a DATE comparison against `ConfirmationFreshness::staleBefore`
     * rather than a state computed per row, so this is one indexed pass over
     * `item_confirmation` however long the list would have been.
     *
     * @return list<array<string, mixed>>
     */
    private function staleNearby(Connection $db, User $user, ConfirmationFreshness $freshness): array
    {
        $ages = array_values(array_filter(
            array_map(static fn (ItemType $t): string => $t->letter(), ItemType::cases()),
            static fn (string $l): bool => ItemType::fromLetter($l)?->confirmationAges() ?? false,
        ));
        if ([] === $ages) {
            return [];
        }
        $rows = $db->fetchAllAssociative(
            'SELECT i.id, i.name, i.letter, last.at AS last_confirmed,
                    round((ST_Distance(i.geom::geography, u.base_point::geography) / 1000)::numeric, 1) AS km
               FROM users u
               JOIN item i ON i.letter IN (:letters) AND i.state IN '.ItemState::servedSqlTuple()."
               JOIN LATERAL (
                    SELECT max(c.created_at) AS at FROM item_confirmation c
                     WHERE c.item_id = i.id AND c.source <> 'form'
               ) last ON last.at IS NOT NULL
              WHERE u.id = :uid
                AND u.base_point IS NOT NULL
                AND last.at < :cut
                AND ST_DWithin(i.geom::geography, u.base_point::geography, u.base_radius_km * 1000)
              ORDER BY last.at ASC, i.id ASC
              LIMIT 12",
            [
                'uid' => (int) $user->getId(),
                'letters' => $ages,
                'cut' => $freshness->staleBefore(new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
            ['letters' => ArrayParameterType::STRING],
        );
        foreach ($rows as &$row) {
            $row['typeLabelKey'] = ItemType::fromParam((string) $row['letter'])->labelKey();
        }

        /* @var list<array<string, mixed>> */
        return $rows;
    }

    /**
     * The user's place confirmations ("still here?" / potability stances,
     * moderation-and-contribution.md §1.6) with the item-type label key
     * resolved from the catalog letter.
     *
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
