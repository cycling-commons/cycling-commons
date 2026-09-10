<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Elevation\ClimbProfiler;
use App\Elevation\ElevationClient;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Server-side climb profile (not a public DEM).
 *
 * @see docs/specs/climb-elevation.md §2
 * @see docs/specs/security-architecture.md §7
 *
 * @api
 */
final class ElevationController extends AbstractController
{
    #[Route('/contribute/elevation', name: 'contribute_elevation', methods: ['POST'])]
    public function elevation(
        Request $request,
        ClimbProfiler $profiler,
        RateLimiterFactoryInterface $elevationLimiter,
    ): JsonResponse {
        // docs/specs/security-architecture.md §5.1 — 401 not 302; stateless X-CC-Token.
        $user = $this->getUser();
        if (!$this->isGranted('ROLE_USER') || !$user instanceof User) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'authentication_required');
        }
        if (!$this->isCsrfTokenValid('elevation', (string) $request->headers->get('X-CC-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

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

        $steepAt = null;
        $rawSteep = $payload['steepAt'] ?? null;
        if (isset($rawSteep[0], $rawSteep[1]) && is_numeric($rawSteep[0]) && is_numeric($rawSteep[1])) {
            $steepAt = [(float) $rawSteep[0], (float) $rawSteep[1]];
        }

        // docs/specs/security-architecture.md §7 — per-user; after cheap validation.
        if (!$elevationLimiter->create('user-'.(string) $user->getId())->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'rate_limited'], 429);
        }

        $profile = $profiler->profile($coords, $steepAt);
        if (null === $profile) {
            // docs/specs/climb-elevation.md §2d — 503, not 200-with-nulls.
            return new JsonResponse(['error' => 'elevation_unavailable'], 503);
        }

        return new JsonResponse($profile);
    }
}
