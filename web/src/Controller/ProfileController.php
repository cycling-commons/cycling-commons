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

        // docs/specs/moderation-and-contribution.md §8 — hide swept rejected/withdrawn even if sweep has not run.
        $contributionsQuery = static function (EntityManagerInterface $em) use ($userId, $retention, $letterFilter, $statusFilter) {
            $qb = $em->createQueryBuilder()
                ->from(Submission::class, 's')
                ->where('s.userId = :uid')
                ->andWhere('(s.status NOT IN (:swept) OR s.decidedAt IS NULL OR s.decidedAt >= :cutoff)')
                ->setParameter('uid', $userId)
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
            'letter_chips' => array_values(array_filter(array_map(
                static fn (ItemType $t): ?array => \in_array($t->letter(), $letters, true)
                    ? ['letter' => $t->letter(), 'labelKey' => $t->labelKey()]
                    : null,
                ItemType::cases(),
            ))),
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
            'route_proposals' => $em->getRepository(RecommendedRoute::class)->findBy(
                ['proposedBy' => $userId],
                ['createdAt' => 'DESC', 'id' => 'DESC'],
                $routePager['perPage'],
                $routePager['offset'],
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
