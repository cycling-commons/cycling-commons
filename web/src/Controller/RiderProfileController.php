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
 * Public rider profile (account-and-auth.md §7): exists only while the rider's
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

        /* The counter boundaries are editorial and deliberate (docs/TODO.md,
           owner 2026-08-13): count APPROVED work only - a counter of pending
           submissions is a spam incentive with a scoreboard; votes stay
           private (an opinion is not a contribution); moderation counts stay
           admin-only. "Checks" merges every verify-reality act (confirmations
           of any stance) into ONE counter, because three separate ones invite
           gaming the easiest. */
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
            // Photos still standing: a granted takedown deletes the objects
            // (objects_deleted_at), and a deleted photo is not a contribution
            // a profile should keep scoring.
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
            'counters' => $counters,
            'routes' => $routes,
            'pending_count' => $pendingCount,
        ]);
    }
}
