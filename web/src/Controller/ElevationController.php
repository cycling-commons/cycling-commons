<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Elevation\ElevationClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Elevation for a drawn climb line.
 *
 * Exists so the climb editor asks *us* rather than a third party's public API
 * from the browser. That moves the dataset behind a setting instead of a CORS
 * allowlist, removes an external host from the page's CSP, and means the
 * gradient a rider sees comes from the source this project chose
 * (climb-elevation.md §2a).
 *
 * Contributor-only: it is an editor tool, not public map data, and it costs an
 * upstream request per call. Rate limiting rides on the same login the
 * contribute flow already requires.
 */
final class ElevationController extends AbstractController
{
    #[Route('/contribute/elevation', name: 'contribute_elevation', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function elevation(Request $request, ElevationClient $client): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $raw = \is_array($payload) ? ($payload['coords'] ?? null) : null;
        if (!\is_array($raw) || [] === $raw || \count($raw) > ElevationClient::MAX_POINTS) {
            return new JsonResponse(['error' => 'bad_request'], 400);
        }

        $coords = [];
        foreach ($raw as $pair) {
            if (!\is_array($pair) || !isset($pair[0], $pair[1]) || !is_numeric($pair[0]) || !is_numeric($pair[1])) {
                return new JsonResponse(['error' => 'bad_request'], 400);
            }
            $lat = (float) $pair[0];
            $lng = (float) $pair[1];
            if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
                return new JsonResponse(['error' => 'bad_request'], 400);
            }
            $coords[] = [$lat, $lng];
        }

        $result = $client->heights($coords);
        if (null === $result) {
            // 503, not 200-with-nulls: "we could not measure this" is a
            // different outcome from "this is flat", and the editor has to be
            // able to tell them apart to say so honestly (§2d).
            return new JsonResponse(['error' => 'elevation_unavailable'], 503);
        }

        return new JsonResponse($result);
    }
}
