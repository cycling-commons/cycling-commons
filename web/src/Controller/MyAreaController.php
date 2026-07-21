<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\BaseLocationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Set my area" write path (region-scoping-design.md §4/§6): a rider picks a
 * town or drops a pin and this is where the base point lands. Stateless JSON
 * API in the ride-check/best-of mould — no locale prefix, `my-area` CSRF
 * token id carried in the X-CSRF-Token header. BaseLocationService owns the
 * coarsen+clamp+derive; the response echoes back only the STORED coarse
 * values it re-reads from the user afterwards, never the raw request body —
 * §4's privacy invariant is "requests transmit only the already-coarse
 * stored value (request precision == stored precision)".
 *
 * @api Instantiated by Symfony's router; called by assets/map/scope.js.
 */
final class MyAreaController extends AbstractController
{
    #[Route('/map/my-area', name: 'map_my_area_set', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function set(Request $request, BaseLocationService $baseLocations, EntityManagerInterface $em): JsonResponse
    {
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
        // Reject before touching the entity (region-scoping-design.md §4):
        // malformed input must never reach BaseLocationService::apply().
        if (!\is_numeric($lat) || !\is_numeric($lng) || abs((float) $lat) > 90.0 || abs((float) $lng) > 180.0) {
            return new JsonResponse(['error' => 'bad_coords'], 400);
        }

        /** @var User $user */
        $user = $this->getUser();
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
