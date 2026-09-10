<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\BaseLocationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Set-my-area write: 401 not 302; echo stored coarse values, never the raw body.
 *
 * @see docs/specs/map-and-search.md §4.5
 *
 * @api
 */
final class MyAreaController extends AbstractController
{
    #[Route('/map/my-area', name: 'map_my_area_set', methods: ['POST'])]
    public function set(Request $request, BaseLocationService $baseLocations, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$this->isGranted('ROLE_USER') || !$user instanceof User) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'authentication_required');
        }
        if (!$this->isCsrfTokenValid('my-area', (string) $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $body = json_decode($request->getContent(), true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'bad_json'], 400);
        }
        if (!\is_array($body)) {
            return new JsonResponse(['error' => 'bad_json'], 400);
        }

        $lat = $body['lat'] ?? null;
        $lng = $body['lng'] ?? null;
        // docs/specs/map-and-search.md §4.5 — reject before touching the entity.
        if (!\is_numeric($lat) || !\is_numeric($lng) || abs((float) $lat) > 90.0 || abs((float) $lng) > 180.0) {
            return new JsonResponse(['error' => 'bad_coords'], 400);
        }

        $radius = \is_numeric($body['radiusKm'] ?? null) ? (int) $body['radiusKm'] : $user->getBaseRadiusKm();
        $place = \is_string($body['place'] ?? null) ? $body['place'] : null;

        $baseLocations->apply($user, (float) $lat, (float) $lng, $place, $radius);
        $em->flush();

        return new JsonResponse([
            'lat' => $user->getBaseLat(),
            'lng' => $user->getBaseLng(),
            'radiusKm' => $user->getBaseRadiusKm(),
            'place' => $user->getBasePlace(),
            'regionIds' => $user->getBaseRegionIds(),
            'countryCodes' => $user->getBaseCountryCodes(),
        ]);
    }
}
