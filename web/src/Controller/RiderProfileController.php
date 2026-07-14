<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemState;
use App\Catalog\SubmissionStatus;
use App\Entity\User;
use App\Routing\LocalePrefix;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Public rider profile (spec 2026-07-14): exists only while the rider's
 * publicProfile toggle is ON — the "view as others see it" link renders this
 * exact page with zero owner special-casing. Public-appropriate data only.
 *
 * @api Instantiated by Symfony's router — `@api` tells Psalm this is a live
 *      entry point, not dead code.
 */
#[Route(LocalePrefix::PATHS)]
final class RiderProfileController extends AbstractController
{
    #[Route('/riders/{uuid}', name: 'rider_profile', requirements: ['uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'])]
    public function show(string $uuid, EntityManagerInterface $em): Response
    {
        $rider = $em->getRepository(User::class)->findOneBy(['uuid' => Uuid::fromString($uuid)]);
        if (null === $rider || !$rider->isPublicProfile()) {
            throw $this->createNotFoundException('No public profile.');
        }

        $contribCount = (int) $em->createQueryBuilder()
            ->select('COUNT(s.id)')->from(Submission::class, 's')
            ->where('s.userId = :uid')->andWhere('s.status = :st')
            ->setParameter('uid', (int) $rider->getId())
            ->setParameter('st', SubmissionStatus::Approved)
            ->getQuery()->getSingleScalarResult();

        // Verified routes are shown by name; anything not yet fully verified
        // only as a count (spec: never leak un-vetted route names on a public
        // surface). "Not yet fully verified" spans BOTH pre-Verified lifecycle
        // states: Submitted (awaiting curator decision) AND Unverified
        // (curator-approved, publicly served, awaiting ride-verification).
        $routes = $em->getRepository(RecommendedRoute::class)->findBy(
            ['proposedBy' => (int) $rider->getId(), 'state' => ItemState::Verified],
            ['createdAt' => 'DESC', 'id' => 'DESC'],
            10,
        );
        $pendingCount = (int) $em->createQueryBuilder()
            ->select('COUNT(r.id)')->from(RecommendedRoute::class, 'r')
            ->where('r.proposedBy = :uid')->andWhere('r.state IN (:pending)')
            ->setParameter('uid', (int) $rider->getId())
            ->setParameter('pending', [ItemState::Submitted, ItemState::Unverified])
            ->getQuery()->getSingleScalarResult();

        return $this->render('profile/public.html.twig', [
            'page_title' => 'profile.public_meta_title',
            'page_description' => 'profile.public_meta_description',
            'nav_active' => '',
            'rider' => $rider,
            'contrib_count' => $contribCount,
            'routes' => $routes,
            'pending_count' => $pendingCount,
        ]);
    }
}
