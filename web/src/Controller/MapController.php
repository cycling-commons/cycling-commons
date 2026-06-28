<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

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
        return $this->render('map/index.html.twig');
    }
}
