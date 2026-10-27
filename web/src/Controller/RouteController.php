<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Contribution\DrawnRoadFacts;
use App\Elevation\RouteSnapper;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Climb-editor road snap via our Valhalla; OSRM response shape kept on purpose.
 *
 * Hardened to match its sibling
 * /contribute/elevation exactly. Both POST JSON from the same two editor pages
 * to the same upstream Valhalla, and this one had login as its only guard:
 * `#[IsGranted]` alone, no stateless token, no quota. Login is not a quota, and
 * an `#[IsGranted]` denial for an anonymous caller is a 302 to the login page,
 * which a fetch() client reads as a broken response body.
 *
 * @see docs/specs/climb-elevation.md §3e
 * @see docs/specs/security-architecture.md §5.1 (THE stateless-JSON pattern)
 * @see docs/specs/security-architecture.md §7 (upstream quotas)
 *
 * @api
 */
final class RouteController extends AbstractController
{
    #[Route('/contribute/route', name: 'contribute_route', methods: ['POST'])]
    public function route(
        Request $request,
        RouteSnapper $snapper,
        RateLimiterFactoryInterface $routeSnapLimiter,
    ): JsonResponse {
        // docs/specs/security-architecture.md §5.1 — 401 not 302; stateless X-CC-Token.
        $user = $this->getUser();
        if (!$this->isGranted('ROLE_USER') || !$user instanceof User) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'authentication_required');
        }
        // 'route-snap', not 'route': 'route-community' already exists and means
        // something else entirely (route-domain community actions).
        if (!$this->isCsrfTokenValid('route-snap', (string) $request->headers->get('X-CC-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

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

        // docs/specs/security-architecture.md §7 — per-user, and after the cheap
        // validation above, so a malformed body costs the caller no budget.
        if (!$routeSnapLimiter->create('user-'.(string) $user->getId())->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'rate_limited'], 429);
        }

        // A drawing editor's line follows the road as it lies (RouteSnapper::follow()).
        $snapped = $snapper->follow($points['a'], $points['b']);
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

    /** The most points a drawn road may send, as the stored segment line (edit-items/A-road-surface.md). */
    private const int MAX_LINE_POINTS = 3000;

    /**
     * What OpenStreetMap says about the road a line in the add form follows (edit-items/A-road-surface.md, "The
     * form reads the road it follows"): the ways under it and the name, road type and surface of the longest. The
     * same guard and quota as the road snap, which the form calls just before.
     */
    #[Route('/contribute/road-ways', name: 'contribute_road_ways', methods: ['POST'])]
    public function roadWays(
        Request $request,
        DrawnRoadFacts $facts,
        RateLimiterFactoryInterface $routeSnapLimiter,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$this->isGranted('ROLE_USER') || !$user instanceof User) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'authentication_required');
        }
        if (!$this->isCsrfTokenValid('route-snap', (string) $request->headers->get('X-CC-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $payload = json_decode($request->getContent(), true);
        $raw = \is_array($payload) ? ($payload['line'] ?? null) : null;
        if (!\is_array($raw) || \count($raw) < 2 || \count($raw) > self::MAX_LINE_POINTS) {
            return new JsonResponse(['error' => 'bad_request'], 400);
        }
        $line = [];
        foreach ($raw as $p) {
            if (!\is_array($p) || !is_numeric($p[0] ?? null) || !is_numeric($p[1] ?? null)) {
                return new JsonResponse(['error' => 'bad_request'], 400);
            }
            $lng = (float) $p[0];
            $lat = (float) $p[1];
            if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
                return new JsonResponse(['error' => 'bad_request'], 400);
            }
            $line[] = [$lng, $lat];
        }

        if (!$routeSnapLimiter->create('user-'.(string) $user->getId())->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'rate_limited'], 429);
        }

        return new JsonResponse($facts->of($line));
    }
}
