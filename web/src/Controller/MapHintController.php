<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Map\MapHint;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Close a map hint for the logged-in person: 401 not 302, 404 for a name that
 * is not a MapHint, 204 once stored. Closing twice stores it once.
 *
 * @see docs/specs/map-and-search.md §4.5
 *
 * @api
 */
final class MapHintController extends AbstractController
{
    #[Route('/map/hint/{hint}/close', name: 'map_hint_close', methods: ['POST'], requirements: ['hint' => '[a-z_]{1,40}'])]
    public function close(string $hint, Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$this->isGranted('ROLE_USER') || !$user instanceof User) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'authentication_required');
        }
        if (!$this->isCsrfTokenValid('map-hint', (string) $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $known = MapHint::tryFrom($hint) ?? throw $this->createNotFoundException('unknown_hint');

        $user->closeHint($known);
        $em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
