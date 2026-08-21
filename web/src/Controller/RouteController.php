<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Elevation\RouteSnapper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Climb-editor road snap via our Valhalla; OSRM response shape kept on purpose.
 *
 * @see docs/specs/climb-elevation.md §3e
 *
 * @api
 */
final class RouteController extends AbstractController
{
    #[Route('/contribute/route', name: 'contribute_route', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function route(Request $request, RouteSnapper $snapper): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $points = [];
        foreach (['a', 'b'] as $key) {
            $raw = \is_array($payload) ? ($payload[$key] ?? null) : null;
            if (!\is_array($raw) || !isset($raw[0], $raw[1]) || !is_numeric($raw[0]) || !is_numeric($raw[1])) {
                return new JsonResponse(['error' => 'bad_request'], 400);
            }
            $lng = (float) $raw[0];
            $lat = (float) $raw[1];
            if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
                return new JsonResponse(['error' => 'bad_request'], 400);
            }
            $points[$key] = [$lat, $lng];
        }

        $snapped = $snapper->snap($points['a'], $points['b']);
        if (null === $snapped) {
            // NoRoute is an answer, not an error — editor keeps the straight line.
            return new JsonResponse(['code' => 'NoRoute', 'routes' => []]);
        }

        return new JsonResponse([
            'code' => 'Ok',
            'routes' => [[
                'geometry' => ['coordinates' => $snapped['coordinates']],
                'distance' => $snapped['distanceM'],
            ]],
        ]);
    }
}
