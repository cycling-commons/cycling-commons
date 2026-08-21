<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * English-only OpenAPI reference (unlocalized on purpose).
 *
 * @see docs/specs/public-api.md §2.3
 *
 * @api
 */
final class ApiReferenceController extends AbstractController
{
    #[Route('/developers/api', name: 'developers_api')]
    public function __invoke(): Response
    {
        return $this->render('pages/developers_api.html.twig');
    }
}
