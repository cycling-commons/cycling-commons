<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The browser reports its time zone while the rider's choice is automatic
 * (docs/specs/account-and-auth.md §9): cc-dates.js sends it only when it
 * differs from the stored one, so times on signed-in pages read in the zone
 * the rider is in. A zone that does not exist is refused, never stored.
 *
 * @api
 */
final class TimeZoneController extends AbstractController
{
    #[Route('/account/time-zone', name: 'account_time_zone', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function report(Request $request, EntityManagerInterface $em): Response
    {
        // docs/specs/security-architecture.md §5.1: stateless X-CC-Token.
        if (!$this->isCsrfTokenValid('time-zone', (string) $request->headers->get('X-CC-Token'))) {
            return new JsonResponse(['error' => 'invalid_token'], Response::HTTP_FORBIDDEN);
        }
        $body = json_decode($request->getContent(), true);
        $zone = \is_array($body) && \is_string($body['zone'] ?? null) ? $body['zone'] : '';
        if (!\in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
            return new JsonResponse(['error' => 'unknown_zone'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var User $user */
        $user = $this->getUser();
        if ($user->getDetectedTimeZone() !== $zone) {
            $user->setDetectedTimeZone($zone);
            $em->flush();
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
