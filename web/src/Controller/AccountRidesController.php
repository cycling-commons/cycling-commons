<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Planner\PlannedRides;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The account's Rides page (docs/specs/route-planner.md section 6.2): the
 * rides a rider planned on the map and saved, theirs alone, newest first,
 * each with a way onto the map and a way to delete it.
 *
 * Their own rows and nothing else: the service is handed the signed-in rider
 * and never an id from the request alone, so another rider's ride is a 404
 * that does not say it exists.
 *
 * @api
 */
final class AccountRidesController extends AbstractController
{
    #[Route('/account/rides', name: 'account_rides', methods: ['GET'])]
    public function index(PlannedRides $rides): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('account/rides.html.twig', [
            'cc_user' => $user,
            'rides' => $rides->listFor($user),
        ]);
    }

    #[Route('/account/rides/{id}/delete', name: 'account_rides_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request, PlannedRides $rides): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('plan-delete', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if (!$rides->delete($user, $id)) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToRoute('account_rides');
    }
}
