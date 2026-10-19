<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Security\TwoFactorPolicy;
use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;
use App\Traffic\SafetyIntake;
use App\Traffic\TrafficDisclosure;
use App\Traffic\TrafficIntake;
use App\Traffic\TrafficPayloadRefused;
use App\Traffic\TrafficView;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Traffic summaries from Scout's ride review: per road piece, never a track.
 * Felt-safety marks from the map: one road piece, safe or unsafe.
 *
 * @see docs/specs/traffic-measurements.md §4.1, §10
 *
 * @api
 */
final class TrafficController extends AbstractController
{
    #[Route('/scout/traffic', name: 'scout_traffic_submit', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function submit(Request $request, TrafficIntake $intake, RateLimiterFactoryInterface $trafficSubmitLimiter): JsonResponse
    {
        // docs/specs/security-architecture.md §5.1: stateless X-CC-Token.
        if (!$this->isCsrfTokenValid('scout-traffic', (string) $request->headers->get('X-CC-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        /** @var User $user */
        $user = $this->getUser();
        if (!$trafficSubmitLimiter->create('user-'.(string) $user->getId())->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'rate_limited'], 429);
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['error' => 'bad_request'], 400);
        }

        try {
            $result = $intake->receive($payload);
        } catch (TrafficPayloadRefused $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(['ok' => true] + $result);
    }

    /**
     * How safe road pieces felt, one answer for one road or several picked
     * together and one time or several (traffic-measurements.md §10). The
     * account is used for the limit only, one token per mark, and is never
     * written next to a mark; the answer says nothing about the waiting room.
     */
    #[Route('/map/road-safety', name: 'map_road_safety', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function roadSafety(Request $request, SafetyIntake $intake, RateLimiterFactoryInterface $roadSafetySubmitLimiter): JsonResponse
    {
        // docs/specs/security-architecture.md §5.1: stateless X-CC-Token.
        if (!$this->isCsrfTokenValid('road-safety', (string) $request->headers->get('X-CC-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['error' => 'bad_request'], 400);
        }
        try {
            $marks = $intake->accept($payload);
        } catch (TrafficPayloadRefused $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }
        // One token per mark: per road and time chosen.
        /** @var User $user */
        $user = $this->getUser();
        if (!$roadSafetySubmitLimiter->create('user-'.(string) $user->getId())->consume(\count($marks))->isAccepted()) {
            return new JsonResponse(['error' => 'rate_limited'], 429);
        }
        $intake->store($marks);

        return new JsonResponse(['ok' => true]);
    }

    /**
     * Measured traffic for the curator layer: only groups that pass the
     * disclosure rules, under the one active grouping scheme.
     *
     * The two-factor check lives here because /map is outside the setup
     * redirect (TwoFactorSetupEnforcer), as for every curator route under /map.
     * While the system setting traffic.map_layer_live is 0 the list does not
     * exist: the map carries no layer to show it. Felt safety per road and
     * group travels beside it (§10).
     *
     * @see docs/specs/traffic-measurements.md §4.6
     */
    #[Route('/map/traffic', name: 'map_traffic', methods: ['GET'])]
    #[IsGranted('ROLE_CURATOR')]
    public function shown(TrafficView $view, TwoFactorPolicy $twoFactorPolicy, SettingsProviderInterface $settings): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User || $twoFactorPolicy->requiresSetup($user)) {
            throw $this->createAccessDeniedException();
        }
        if (1 !== $settings->get(SettingsRegistry::TRAFFIC_MAP_LAYER_LIVE)) {
            throw $this->createNotFoundException();
        }

        $response = new JsonResponse([
            'groups' => TrafficDisclosure::groupsOf($view->scheme()),
            'shown' => $view->shown(),
            'felt' => $view->felt(),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }
}
