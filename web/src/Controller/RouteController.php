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
 * The climb editor's road-snapping, served by us.
 *
 * Same reasoning as /contribute/elevation next door: the editor asks *this*
 * project instead of a third party's public API from the browser. Here the
 * third party was the OSRM demo server, whose own policy forbids production
 * reliance — the one external we called in a rider's hot path with no
 * agreement behind it (external-systems audit 2026-08-09). Our Valhalla —
 * the same instances that answer every elevation request — does the routing.
 *
 * The response shape is OSRM's ({code, routes[0].geometry.coordinates,
 * distance}), kept deliberately: the editor's parsing, its no-route handling
 * and its tests predate this proxy, and a translated shape would have churned
 * all three for zero rider-visible difference.
 *
 * Contributor-only, like elevation: an editor tool with an upstream cost per
 * call, not public map data.
 *
 * @api Instantiated by Symfony's router; called by assets/contribute/climb-editor.js.
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
            // [lng, lat] on the wire — the editor holds GeoJSON order.
            $lng = (float) $raw[0];
            $lat = (float) $raw[1];
            if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
                return new JsonResponse(['error' => 'bad_request'], 400);
            }
            $points[$key] = [$lat, $lng];
        }

        $snapped = $snapper->snap($points['a'], $points['b']);
        if (null === $snapped) {
            // "No road between these points" is an answer, not an error: the
            // editor keeps its straight line and tells the rider (the same
            // contract OSRM's code!=Ok branch already implemented).
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
