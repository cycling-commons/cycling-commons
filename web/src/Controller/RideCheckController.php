<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

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
 * Ride-check intake (map-and-search.md §9): a logged-in rider uploads a GPX
 * and gets back the catalog items along it. Stateless JSON API in the
 * RouteCommunityController mould — in-controller auth (clean 401, never a
 * login redirect), stateless CSRF (`ride-check` token id), per-user daily
 * rate limit. The GPX is read from the request, answered, and discarded:
 * nothing is persisted anywhere on this path.
 *
 * @api Instantiated by Symfony's router; called by assets/map/map.js.
 */
final class RideCheckController extends AbstractController
{
    #[Route('/map/ride-check', name: 'map_ride_check', methods: ['POST'])]
    public function check(
        Request $request,
        RideCheckService $service,
        TranslatorInterface $translator,
        RateLimiterFactoryInterface $rideCheckLimiter,
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
        // A file PHP itself refused is NOT "no file chosen" — the rider picked
        // one, and telling them to pick one is a dead end that hides a size
        // problem they could act on. UPLOAD_ERR_INI_SIZE/FORM_SIZE means the GPX
        // exceeded upload_max_filesize (16M, web/Dockerfile); anything else here
        // is a partial or interrupted transfer, which retrying can fix.
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
            // message = translation key (GpxParser / RideCheckService convention)
            return $this->json(['error' => $translator->trans($e->getMessage())], 422);
        }
    }
}
