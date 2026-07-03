<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use App\Catalog\CatalogProvider;
use App\Moderation\SampleQueue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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

    /**
     * The whole catalog as one cacheable JSON payload — spec §7's named interim
     * until vector tiles. Letters key the layers; values are the fixture shapes
     * map.js has always consumed. Public data only (unverified/verified rows).
     */
    #[Route('/map/catalog.json', name: 'map_catalog', methods: ['GET'])]
    public function catalog(Request $request, CatalogProvider $catalog): Response
    {
        $json = $catalog->json();
        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        $response->setEtag(md5($json));
        $response->setPublic();
        $response->setMaxAge(3600);
        $response->isNotModified($request);

        return $response;
    }
}
