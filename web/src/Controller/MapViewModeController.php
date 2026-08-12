<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
 * Persists a logged-in rider's manual Curated/Everything choice to their
 * profile. The owner chose the
 * profile over localStorage deliberately: people share computers, and a
 * device-scoped default leaks one person's choice to the next.
 *
 * Anonymous visitors never reach this — the map writes their choice to
 * localStorage instead, which is session-scoped by nature.
 *
 * Unlocalized `/map/…` JSON endpoint, matching the other small map POSTs
 * (my-area, ride-check, item confirmations), with the same stateless CSRF.
 *
 * @api Instantiated by Symfony's router; called by assets/map/panels.js.
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
            // A clean 401 rather than a login redirect: the caller is fetch(),
            // and the map must not swallow an HTML login page as JSON.
            return $this->json(['error' => 'unauthenticated'], 401);
        }
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // The wire value is the map toggle's own token ('curated' | 'all'), not
        // the enum value — see MapViewMode::clientToken() for why those differ.
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
