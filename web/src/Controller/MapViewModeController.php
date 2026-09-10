<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\MapViewMode;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Persist view mode on the profile (shared-device leak otherwise).
 *
 * @see docs/specs/map-and-search.md §4.2
 *
 * @api
 */
final class MapViewModeController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'map-view-mode';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/map/view-mode', name: 'map_view_mode', methods: ['POST'])]
    public function set(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            // 401 not 302: fetch() must not swallow a login HTML page as JSON.
            return $this->json(['error' => 'unauthenticated'], 401);
        }
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $mode = match ((string) $request->request->get('mode')) {
            'curated' => MapViewMode::Curated,
            'confirmed' => MapViewMode::Confirmed,
            'all' => MapViewMode::Everything,
            'auto' => MapViewMode::Auto,
            default => null,
        };
        if (null === $mode) {
            return $this->json(['error' => 'invalid_mode'], 422);
        }

        $user->setDefaultMapMode($mode);
        $this->em->flush();

        return $this->json(['mode' => $mode->value]);
    }
}
