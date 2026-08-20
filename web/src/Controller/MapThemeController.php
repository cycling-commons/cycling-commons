<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\MapTheme;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Persists a logged-in rider's map chrome theme (dark/light) to their
 * profile, so it follows them across devices. Same owner reasoning as the
 * view mode: people share computers, and a device-scoped default leaks one
 * person's choice to the next.
 *
 * Anonymous visitors never reach this — the map writes their choice to
 * localStorage instead, which is session-scoped by nature.
 *
 * Unlocalized `/map/…` JSON endpoint, matching the other small map POSTs
 * (view-mode, my-area, ride-check), with the same stateless CSRF.
 *
 * @api Instantiated by Symfony's router; called by assets/map/theme.js.
 */
final class MapThemeController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'map-theme';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/map/theme', name: 'map_theme', methods: ['POST'])]
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

        $theme = MapTheme::tryFrom((string) $request->request->get('theme'));
        if (null === $theme) {
            return $this->json(['error' => 'invalid_theme'], 422);
        }

        $user->setMapTheme($theme);
        $this->em->flush();

        return $this->json(['theme' => $theme->value]);
    }
}
