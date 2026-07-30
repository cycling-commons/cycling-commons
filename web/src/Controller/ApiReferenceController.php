<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The interactive public-API reference: Redoc rendering the design-first
 * OpenAPI contract at public/api/openapi.yaml (the machine-readable companion
 * to docs/specs/public-api.md).
 *
 * Deliberately outside the LocalePrefix page tree: developer reference
 * documentation is English-only, so the page has one clean URL and stays out
 * of the four-locale catalog parity.
 *
 * @api Instantiated by Symfony's router, never referenced from code.
 */
final class ApiReferenceController extends AbstractController
{
    #[Route('/developers/api', name: 'developers_api')]
    public function __invoke(): Response
    {
        return $this->render('pages/developers_api.html.twig');
    }
}
