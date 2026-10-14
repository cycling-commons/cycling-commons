<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Planner\Entity\PlannedRide;
use App\Planner\PlannedRides;
use App\Planner\RideInput;
use App\Planner\RideRefused;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The planner's saved rides (docs/specs/route-planner.md section 6.1 and
 * 8): a signed-in rider saves, opens, lists and deletes their own rides as
 * JSON. The stateless-JSON pattern (security-architecture.md section 5.1):
 * a clean 401 for a visitor, never a redirect; a stateless `X-CC-Token` on
 * the writes, a 403 when it is bad or cross-site; the body's shape checked
 * before any budget is spent; another rider's ride is a 404, as a missing
 * one is.
 *
 * @api
 */
final class PlanRideController extends AbstractController
{
    #[Route('/map/plan/save', name: 'map_plan_save', methods: ['POST'])]
    public function save(Request $request, PlannedRides $rides, RateLimiterFactoryInterface $planSaveLimiter): JsonResponse
    {
        $rider = $this->requireRider();
        $this->requireToken($request, 'plan-save');
        $input = RideInput::parse(json_decode($request->getContent(), true));
        if (null === $input) {
            return new JsonResponse(['error' => 'bad_request'], 400);
        }
        if (!$planSaveLimiter->create('user-'.(string) $rider->getId())->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'rate_limited'], 429);
        }

        try {
            $ride = $rides->save($rider, $input);
        } catch (RideRefused $refused) {
            return new JsonResponse(['error' => $refused->reason], match ($refused->reason) {
                RideRefused::NOT_FOUND => 404,
                RideRefused::TOO_MANY_RIDES => 409,
                default => 400,
            });
        }

        return new JsonResponse(['id' => $ride->getId()]);
    }

    #[Route('/map/plan/ride/{id}', name: 'map_plan_ride', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function ride(int $id, PlannedRides $rides): JsonResponse
    {
        $ride = $rides->find($this->requireRider(), $id) ?? throw $this->createNotFoundException();

        return new JsonResponse(self::summary($ride) + [
            'line' => json_decode($ride->getLine(), true, 512, \JSON_THROW_ON_ERROR),
        ]);
    }

    #[Route('/map/plan/rides', name: 'map_plan_rides', methods: ['GET'])]
    public function rides(PlannedRides $rides): JsonResponse
    {
        return new JsonResponse(['rides' => array_map(self::summary(...), $rides->listFor($this->requireRider()))]);
    }

    #[Route('/map/plan/ride/{id}/delete', name: 'map_plan_ride_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request, PlannedRides $rides): Response
    {
        $rider = $this->requireRider();
        $this->requireToken($request, 'plan-delete');
        if (!$rides->delete($rider, $id)) {
            throw $this->createNotFoundException();
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * What the list shows of a ride: everything but the line.
     *
     * @return array<string, mixed>
     */
    private static function summary(PlannedRide $ride): array
    {
        return [
            'id' => $ride->getId(),
            'name' => $ride->getName(),
            'bike' => $ride->getBike()->value,
            'stops' => $ride->getStops(),
            'distanceM' => $ride->getDistanceM(),
            'ascentM' => $ride->getAscentM(),
            'descentM' => $ride->getDescentM(),
            'updatedAt' => $ride->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    /** A JSON client is never sent to the login page: 401, not 302. The ROLE_USER check also catches a second factor in progress. */
    private function requireRider(): User
    {
        $user = $this->getUser();
        if (!$this->isGranted('ROLE_USER') || !$user instanceof User) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'authentication_required');
        }

        return $user;
    }

    /** A plain 403 for a bad or cross-site token, the planner's other calls' answer. */
    private function requireToken(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->headers->get('X-CC-Token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
