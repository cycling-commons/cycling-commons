<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemType;
use App\Catalog\SubmissionStatus;
use App\Contribution\SubmissionChangeSummary;
use App\Entity\User;
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

    #[Route('/profile', name: 'profile')]
    public function show(
        Request $request,
        EntityManagerInterface $em,
        RetentionService $retention,
        Connection $db,
        SubmissionChangeSummary $changes,
        PageSize $pageSize,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $userId = (int) $user->getId();

        // Lazy retention filter (correct even if no sweep has run yet, M8
        // phase 1): a rejected submission past the cutoff must never render
        // here, whether or not RetentionService::sweep() has deleted it. The
        // count applies the SAME filter as the page query - a total that counts
        // rows the list refuses to show would page into empty tails.
        $contributionsQuery = static fn (EntityManagerInterface $em) => $em->createQueryBuilder()
            ->from(Submission::class, 's')
            ->where('s.userId = :uid')
            ->andWhere('(s.status != :rejected OR s.decidedAt IS NULL OR s.decidedAt >= :cutoff)')
            ->setParameter('uid', $userId)
            ->setParameter('rejected', SubmissionStatus::Rejected)
            ->setParameter('cutoff', $retention->cutoff());

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
