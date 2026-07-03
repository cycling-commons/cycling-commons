<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use App\Moderation\SampleQueue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the full-screen interactive map shell.
 *
 * @api Instantiated by Symfony's router, never referenced from code — `@api`
 *      tells Psalm this (and its actions) is a live entry point, not dead code.
 */
final class MapController extends AbstractController
{
    #[Route('/map', name: 'map')]
    public function map(): Response
    {
        $params = [];

        // Curator-only: hand the pending submissions to the map so the moderation
        // layer can render. Riders never receive this — it is emitted only inside
        // the template's is_granted('ROLE_CURATOR') block (no leak of un-vetted data).
        if ($this->isGranted('ROLE_CURATOR')) {
            $params['pending'] = SampleQueue::items();
        }

        return $this->render('map/index.html.twig', $params);
    }
}
