<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemType;
use App\Catalog\SubmissionStatus;
use App\Entity\User;
use App\Moderation\RetentionService;
use App\Routing\LocalePrefix;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    #[Route('/profile', name: 'profile')]
    public function show(EntityManagerInterface $em, RetentionService $retention, Connection $db): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Lazy retention filter (correct even if no sweep has run yet, M8
        // phase 1): a rejected submission past the cutoff must never render
        // here, whether or not RetentionService::sweep() has deleted it.
        /** @var list<Submission> $contributions */
        $contributions = $em->createQueryBuilder()
            ->select('s')
            ->from(Submission::class, 's')
            ->where('s.userId = :uid')
            ->andWhere('(s.status != :rejected OR s.decidedAt IS NULL OR s.decidedAt >= :cutoff)')
            ->setParameter('uid', (int) $user->getId())
            ->setParameter('rejected', SubmissionStatus::Rejected)
            ->setParameter('cutoff', $retention->cutoff())
            ->orderBy('s.createdAt', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();

        return $this->render('profile/show.html.twig', [
            'page_title' => 'meta.profile_title',
            'page_description' => 'meta.profile_description',
            'nav_active' => '',
            'cc_user' => $user,
            'contributions' => $contributions,
            'route_proposals' => $em->getRepository(RecommendedRoute::class)->findBy(
                ['proposedBy' => (int) $user->getId()],
                ['createdAt' => 'DESC', 'id' => 'DESC'],
                50,
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
                ['uid' => (int) $user->getId()],
            ),
            'confirmations' => $this->confirmations($db, (int) $user->getId()),
            // The answer to "where is my curator request?" lives on the landing
            // pane.
            'curator_applications' => $db->fetchAllAssociative(
                'SELECT ca.country_code, ca.status, ca.created_at, ca.decision_note, r.slug AS region_slug
                   FROM curator_application ca
                   LEFT JOIN region r ON r.id = ca.requested_region_id
                  WHERE ca.user_id = :uid
                  ORDER BY ca.created_at DESC, ca.id DESC',
                ['uid' => (int) $user->getId()],
            ),
            // Whether the rider's OWN area has anyone looking after it. Someone
            // who has never applied is not "a curator with no applications" —
            // they are a rider, and the only thing worth telling them here is
            // whether their patch needs somebody.
            'home_region' => $this->homeRegion($db, $user),
        ]);
    }

    /**
     * The first of the rider's derived base regions — the one containing their
     * base point (map-and-search.md §4.5 puts it first) — and whether anyone
     * curates it.
     *
     * Country-level moderators count: a moderator scoped to NL looks after
     * every Dutch region, so treating those regions as uncovered would send
     * riders to apply for work that is already being done.
     *
     * @return array{slug: string, covered: bool}|null null when no base
     *                                                 location is set, which is the "we cannot say" case
     */
    private function homeRegion(Connection $db, User $user): ?array
    {
        $ids = $user->getBaseRegionIds();
        if ([] === $ids) {
            return null;
        }

        $row = $db->fetchAssociative(
            'SELECT r.slug,
                    EXISTS (
                      SELECT 1 FROM moderator_area ma
                       WHERE ma.region_id = r.id
                          OR ma.country_code = r.country_code
                    ) AS covered
               FROM region r WHERE r.id = :id',
            ['id' => $ids[0]],
        );

        return false === $row ? null : ['slug' => (string) $row['slug'], 'covered' => (bool) $row['covered']];
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
