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
 * Persist map chrome theme on the profile (shared-device leak otherwise).
 *
 * @see docs/specs/map-and-search.md §4.6
 *
 * @api
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
            // 401 not 302: fetch() must not swallow a login HTML page as JSON.
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
