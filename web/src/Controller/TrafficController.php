<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Security\TwoFactorPolicy;
use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;
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
 *
 * @see docs/specs/traffic-measurements.md §4.1
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
     * Measured traffic for the curator layer: only groups that pass the
     * disclosure rules, under the one active grouping scheme.
     *
     * The two-factor check lives here because /map is outside the setup
     * redirect (TwoFactorSetupEnforcer), as for every curator route under /map.
     * While the system setting traffic.map_layer_live is 0 the list does not
     * exist: the map carries no layer to show it.
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
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }
}
