<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Map\MapPresets;
use App\Map\PresetInput;
use App\Map\PresetRefused;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Map presets (docs/specs/map-presets.md section 5): a signed-in rider
 * lists, saves, defaults and deletes their own presets as JSON. The
 * stateless-JSON pattern (security-architecture.md section 5.1): a clean 401
 * for a visitor, a stateless `X-CC-Token` on every write, the shape checked
 * before the budget is spent.
 */
final class MapPresetController extends AbstractController
{
    private const string TOKEN = 'map-preset';

    #[Route('/map/presets', name: 'map_presets', methods: ['GET'])]
    public function list(MapPresets $presets): JsonResponse
    {
        $rider = $this->requireRider();

        return new JsonResponse(['presets' => $presets->rowsFor($rider)]);
    }

    #[Route('/map/presets/save', name: 'map_presets_save', methods: ['POST'])]
    public function save(Request $request, MapPresets $presets, RateLimiterFactoryInterface $mapPresetSaveLimiter): JsonResponse
    {
        $rider = $this->requireRider();
        $this->requireToken($request);
        $input = PresetInput::parse(json_decode($request->getContent(), true));
        if (null === $input) {
            return new JsonResponse(['error' => 'bad_request'], 400);
        }
        if (!$mapPresetSaveLimiter->create('user-'.(string) $rider->getId())->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'rate_limited'], 429);
        }
        try {
            $preset = $presets->save($rider, $input);
        } catch (PresetRefused $e) {
            return new JsonResponse(['error' => $e->reason], PresetRefused::NOT_FOUND === $e->reason ? 404 : 409);
        }

        return new JsonResponse(['id' => $preset->getId()], null === $input->id ? 201 : 200);
    }

    /** Use the preset for one tool (map-presets.md section 1); the tool moves off the preset that had it. */
    #[Route('/map/presets/{id}/use', name: 'map_presets_use', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function useFor(int $id, Request $request, MapPresets $presets): Response
    {
        $rider = $this->requireRider();
        $this->requireToken($request);
        $body = json_decode($request->getContent(), true);
        $context = \is_array($body) ? ($body['context'] ?? null) : null;
        if (!\is_string($context) || !\in_array($context, PresetInput::CONTEXTS, true)) {
            return new JsonResponse(['error' => 'bad_request'], 400);
        }

        return new Response('', $presets->useFor($rider, $id, $context) ? 204 : 404);
    }

    #[Route('/map/presets/{id}/delete', name: 'map_presets_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request, MapPresets $presets): Response
    {
        $rider = $this->requireRider();
        $this->requireToken($request);

        return new Response('', $presets->delete($rider, $id) ? 204 : 404);
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

    private function requireToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->headers->get('X-CC-Token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
