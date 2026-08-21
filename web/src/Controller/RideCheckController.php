<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Account\UnitFormatter;
use App\Catalog\RideCheckService;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Ride-check: 401 not 302; GPX is discarded; nothing persisted.
 *
 * @see docs/specs/map-and-search.md §9
 * @see docs/specs/security-architecture.md §7
 *
 * @api
 */
final class RideCheckController extends AbstractController
{
    #[Route('/map/ride-check', name: 'map_ride_check', methods: ['POST'])]
    public function check(
        Request $request,
        RideCheckService $service,
        TranslatorInterface $translator,
        RateLimiterFactoryInterface $rideCheckLimiter,
        UnitFormatter $units,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$this->isGranted('ROLE_USER') || !$user instanceof User) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'authentication_required');
        }
        if (!$this->isCsrfTokenValid('ride-check', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $file = $request->files->get('gpx');
        if (!$file instanceof UploadedFile) {
            return $this->json(['error' => $translator->trans('ride_check.error.file_required')], 422);
        }
        // PHP size/partial errors are not "no file chosen".
        if (!$file->isValid()) {
            $key = \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                ? 'ride_check.error.file_too_large'
                : 'ride_check.error.upload_failed';

            return $this->json(['error' => $translator->trans($key)], 422);
        }

        if (!$rideCheckLimiter->create('user-'.(string) $user->getId())->consume()->isAccepted()) {
            return $this->json(['error' => $translator->trans('contribute.error.rate_limited')], 429);
        }

        $radius = $request->request->has('radius')
            ? (int) $request->request->get('radius')
            : RideCheckService::DEFAULT_RADIUS;

        try {
            return $this->json($service->check($file->getContent(), $radius));
        } catch (\InvalidArgumentException $e) {
            // Exception message is a translation key; lengths in rider units.
            return $this->json(['error' => $translator->trans($e->getMessage(), [
                '%min%' => $units->shortDistance(RideCheckService::MIN_RAW_M),
                '%max%' => $units->distance(RideCheckService::MAX_RAW_M / 1000, 0),
            ])], 422);
        }
    }
}
