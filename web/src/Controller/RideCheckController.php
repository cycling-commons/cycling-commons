<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Account\UnitFormatter;
use App\Catalog\RideCheckService;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Ride-check: open to anyone; GPX is discarded; nothing persisted.
 *
 * @see docs/specs/map-and-search.md §9
 * @see docs/specs/security-architecture.md §7
 *
 * @api
 */
final class RideCheckController extends AbstractController
{
    /**
     * Salted IP hash for the anonymous limiter: volume, not identity.
     *
     * Hashing is pseudonymisation, not anonymisation — the value is still
     * personal data under the GDPR and is treated as such. It is here so the
     * rate-limit store never holds a plain address. Same construction as the
     * takedown reporter hash.
     *
     * @see docs/specs/security-architecture.md §7
     */
    public static function anonKey(string $ip, string $secret): string
    {
        return 'anon-'.hash('sha256', $secret.'|ride-check|'.$ip);
    }

    #[Route('/map/ride-check', name: 'map_ride_check', methods: ['POST'])]
    public function check(
        Request $request,
        RideCheckService $service,
        TranslatorInterface $translator,
        RateLimiterFactoryInterface $rideCheckLimiter,
        RateLimiterFactoryInterface $rideCheckAnonLimiter,
        #[Autowire('%kernel.secret%')]
        string $secret,
        UnitFormatter $units,
    ): JsonResponse {
        $user = $this->getUser();
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

        // Signed in, the limit is generous and per account. Anonymous, it is
        // small and per address — enough to try the tool before deciding to
        // sign up, not enough to run someone's catalogue searches for free.
        if ($user instanceof User) {
            if (!$rideCheckLimiter->create('user-'.(string) $user->getId())->consume()->isAccepted()) {
                return $this->json(['error' => $translator->trans('contribute.error.rate_limited')], 429);
            }
        } else {
            $key = self::anonKey($request->getClientIp() ?? 'unknown', $secret);
            if (!$rideCheckAnonLimiter->create($key)->consume()->isAccepted()) {
                return $this->json(['error' => $translator->trans('ride_check.error.anon_limit')], 429);
            }
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
