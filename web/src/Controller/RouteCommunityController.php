<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\BikeType;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemState;
use App\Catalog\RouteSuggestionReason;
use App\Catalog\Season;
use App\Community\RouteCommunityService;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Route community JSON: 401 not 302.
 *
 * @see docs/specs/route-domain.md §6
 *
 * @api
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

    /** ROLE_USER, or a clean 401 (never a login redirect). */
    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$this->isGranted('ROLE_USER') || !$user instanceof User) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'authentication_required');
        }

        return $user;
    }

    /** Loads a route that is currently served; 404 otherwise. */
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
        $route = $this->activeRoute($id);

        $bike = BikeType::tryFrom((string) $request->request->get('bike_type'));
        if (null === $bike) {
            return $this->json(['error' => 'invalid_bike_type'], 422);
        }

        $this->community->recordRide($route, $user, $bike);

        return $this->freshSnapshot($route, $user);
    }

    #[Route('/routes/{id}/vote', name: 'route_vote', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function vote(int $id, Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $this->validateCsrf($request);
        $route = $this->activeRoute($id);
        if (ItemState::Verified !== $route->getState()) {
            throw $this->createNotFoundException('Route is not open for voting.');
        }

        $season = Season::tryFrom((string) $request->request->get('season'));
        $bike = BikeType::tryFrom((string) $request->request->get('bike_type'));
        if (null === $season || null === $bike) {
            return $this->json(['error' => 'invalid_vote'], 422);
        }

        $this->community->recordVote($route, $user, $season, $bike);

        return $this->freshSnapshot($route, $user);
    }

    #[Route('/routes/{id}/suggest', name: 'route_suggest', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function suggest(int $id, Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $this->validateCsrf($request);
        $route = $this->activeRoute($id);

        $reason = RouteSuggestionReason::tryFrom((string) $request->request->get('reason'));
        if (null === $reason) {
            return $this->json(['error' => 'invalid_reason'], 422);
        }
        $note = $request->request->get('note');
        $segments = $this->parseSegments($request->request->get('segments'));
        if (false === $segments) {
            return $this->json(['error' => 'invalid_segments'], 422);
        }

        try {
            $this->community->recordSuggestion($route, $user, $reason, \is_string($note) ? $note : null, $segments);
        } catch (TooManyRequestsHttpException) {
            return $this->json(['error' => 'rate_limited'], 429);
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'note_too_long'], 422);
        }

        return $this->json(['ok' => true]);
    }

    /**
     * @return list<array{start: float, end: float}>|false|null
     */
    private function parseSegments(mixed $raw): array|false|null
    {
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!\is_array($decoded) || !array_is_list($decoded)) {
            return false;
        }
        $out = [];
        foreach ($decoded as $seg) {
            if (!\is_array($seg) || !isset($seg['start'], $seg['end']) || !is_numeric($seg['start']) || !is_numeric($seg['end'])) {
                return false;
            }
            $a = (float) $seg['start'];
            $b = (float) $seg['end'];
            if ($a < 0 || $b > 1 || $a > $b) {
                return false;
            }
            $out[] = ['start' => $a, 'end' => $b];
        }
        if (\count($out) > 50) {
            return false;
        }

        return [] === $out ? null : $out;
    }

    /**
     * @see docs/specs/route-domain.md §7
     */
    #[Route('/routes/{id}/corrections', name: 'route_corrections', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function corrections(int $id, Connection $db): JsonResponse
    {
        $this->requireUser();
        if (!$this->isGranted('ROLE_CURATOR')) {
            throw new HttpException(Response::HTTP_FORBIDDEN, 'curator_only');
        }
        $this->activeRoute($id);

        /** @var list<array{id:int|string, reason:string, note:?string, segments:?string}> $rows */
        $rows = $db->fetchAllAssociative(
            "SELECT id, reason, note, segments FROM route_suggestion
             WHERE route_id = :r AND status = 'pending' ORDER BY created_at ASC, id ASC",
            ['r' => $id],
        );

        $corrections = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'reason' => (string) $row['reason'],
            'note' => $row['note'],
            'segments' => null !== $row['segments'] ? json_decode((string) $row['segments'], true) : [],
        ], $rows);

        return $this->json(['corrections' => $corrections]);
    }

    private function validateCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('route-community', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function freshSnapshot(RecommendedRoute $route, User $user): JsonResponse
    {
        $this->em->refresh($route);
        $season = Season::current(new \DateTimeImmutable());

        return $this->json(['ok' => true, ...$this->community->snapshot($route, $user, $season)]);
    }
}
