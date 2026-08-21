<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemState;
use App\Entity\User;
use App\Routing\LocalePrefix;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Public rider profile: exists only while `publicProfile` is ON.
 *
 * @see docs/specs/account-and-auth.md §7
 *
 * @api
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

        // docs/specs/account-and-auth.md §7 — approved work only; votes stay private.
        $db = $em->getConnection();
        /** @var array<string, int> $byType */
        $byType = [];
        foreach ($db->fetchAllAssociative(
            "SELECT type, COUNT(*) AS n FROM submission WHERE user_id = :uid AND status = 'approved' GROUP BY type",
            ['uid' => (int) $rider->getId()],
        ) as $row) {
            $byType[(string) $row['type']] = (int) $row['n'];
        }
        $counters = [
            'places' => $byType['new'] ?? 0,
            'edits' => $byType['edit'] ?? 0,
            // docs/specs/account-and-auth.md §7 — granted takedown stops scoring.
            'photos' => (int) $db->fetchOne(
                "SELECT COUNT(*) FROM media_upload WHERE user_id = :uid AND status = 'approved' AND objects_deleted_at IS NULL",
                ['uid' => (int) $rider->getId()],
            ),
            'checks' => (int) $db->fetchOne(
                'SELECT COUNT(*) FROM item_confirmation WHERE user_id = :uid',
                ['uid' => (int) $rider->getId()],
            ),
        ];
        $contribCount = $counters['places'] + $counters['edits'];

        // docs/specs/account-and-auth.md §7 — names only for Verified; never leak un-vetted routes.
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
            'counters' => $counters,
            'routes' => $routes,
            'pending_count' => $pendingCount,
        ]);
    }
}
