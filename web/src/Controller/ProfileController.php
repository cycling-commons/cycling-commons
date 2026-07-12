<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Entity\User;
use App\Moderation\RetentionService;
use App\Routing\LocalePrefix;
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
    public function show(EntityManagerInterface $em, RetentionService $retention): Response
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
            ->andWhere('s.status != :rejected OR s.decidedAt IS NULL OR s.decidedAt >= :cutoff')
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
        ]);
    }
}
