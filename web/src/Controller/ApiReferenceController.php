<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Controller;

use App\Api\ApiSurface;
use App\Routing\LocalePrefix;
use App\Routing\LocalizedPath;
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
/* These two were the only public pages with no locale prefix, so `/nl/...`
   404'd on them while every sibling page answered. Found while localising the
   slugs (LocalizedPath): the localised paths generated fine and then had
   nothing to sit under. */
#[Route(LocalePrefix::PATHS)]
final class ApiReferenceController extends AbstractController
{
    #[Route(LocalizedPath::DEVELOPERS_API, name: 'developers_api')]
    public function __invoke(ApiSurface $surface): Response
    {
        return $this->render('pages/developers_api.html.twig', [
            'api_live' => $surface->live(),
            'api_total' => $surface->promised(),
        ]);
    }
}
