<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\BikeType;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemState;
use App\Catalog\Season;
use App\Community\RouteCommunityService;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The rider community loop on the route drawer (route-domain spec §7): a
 * per-route snapshot fetched on drawer-open (P3-D3) and the ride/vote/suggest
 * writes. Unlocalized `/routes/{id}/…` JSON API (matches RouteGpxController),
 * user-only + stateless CSRF (P3-D5). Auth is enforced in-controller (401,
 * not a login redirect) because these are API endpoints, not pages.
 *
 * @api Instantiated by Symfony's router; called by assets/map/map.js.
 */
final class RouteCommunityController extends AbstractController
{
    public function __construct(
        private readonly RouteCommunityService $community,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/routes/{id}/community', name: 'route_community', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function snapshot(int $id, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        $user = $this->requireUser();
        $route = $this->activeRoute($id);
        $season = Season::current(new \DateTimeImmutable());

        return $this->json([
            ...$this->community->snapshot($route, $user, $season),
            'season' => $season->value,
            'token' => $csrf->getToken('route-community')->getValue(),
        ]);
    }

    /**
     * The API auth gate: a fully-authenticated ROLE_USER, or a clean 401 — a
     * JSON client must not be 302-redirected to the login page (P3-D5). Also
     * catches 2FA-in-progress tokens (they lack ROLE_USER).
     */
    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$this->isGranted('ROLE_USER') || !$user instanceof User) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'authentication_required');
        }

        return $user;
    }

    /** Loads a route that is currently served (P3-D5); 404 otherwise. */
    private function activeRoute(int $id): RecommendedRoute
    {
        $route = $this->em->find(RecommendedRoute::class, $id);
        if (null === $route || !\in_array($route->getState(), ItemState::SERVED, true)) {
            throw $this->createNotFoundException('No active route.');
        }

        return $route;
    }

    #[Route('/routes/{id}/rode-it', name: 'route_rode_it', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function rodeIt(int $id, Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $this->validateCsrf($request);
        $route = $this->activeRoute($id);   // Unverified + Verified both allowed

        $bike = BikeType::tryFrom((string) $request->request->get('bike_type'));
        if (null === $bike) {
            return $this->json(['error' => 'invalid_bike_type'], 422);
        }

        $this->community->recordRide($route, $user, $bike);

        return $this->freshSnapshot($route, $user);
    }

    private function validateCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('route-community', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function freshSnapshot(RecommendedRoute $route, User $user): JsonResponse
    {
        $this->em->refresh($route);   // pick up a just-flipped state
        $season = Season::current(new \DateTimeImmutable());

        return $this->json(['ok' => true, ...$this->community->snapshot($route, $user, $season)]);
    }
}
