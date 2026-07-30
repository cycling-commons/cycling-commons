<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\Submission;
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
            // The answer to "where is my curator request?" lives on the landing
            // pane (2026-07-29-country-requests-and-curator-signup-design.md §10).
            'curator_applications' => $db->fetchAllAssociative(
                'SELECT ca.country_code, ca.status, ca.created_at, ca.decision_note, r.slug AS region_slug
                   FROM curator_application ca
                   LEFT JOIN region r ON r.id = ca.requested_region_id
                  WHERE ca.user_id = :uid
                  ORDER BY ca.created_at DESC, ca.id DESC',
                ['uid' => (int) $user->getId()],
            ),
        ]);
    }
}
