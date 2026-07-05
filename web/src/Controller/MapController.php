<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use App\Catalog\CatalogProvider;
use App\Catalog\ChangeHistoryView;
use App\Moderation\SubmissionQueue;
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
    public function map(SubmissionQueue $queue): Response
    {
        $params = [];

        // Curator-only: hand the pending submissions to the map so the moderation
        // layer can render. Riders never receive this — it is emitted only inside
        // the template's is_granted('ROLE_CURATOR') block (no leak of un-vetted data).
        if ($this->isGranted('ROLE_CURATOR')) {
            $params['pending'] = $queue->pendingForMap();
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

    /**
     * Per-item change log (design spec W5): who changed what, when — newest
     * first. Public, read-only; feeds the map drawer's "Recent changes"
     * (C1-T3). Unknown/never-edited items simply have no rows — 200 with an
     * empty list, not 404, so the drawer never has to special-case it.
     */
    #[Route('/map/item/{id}/history', name: 'map_item_history', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function history(int $id, Request $request, ChangeHistoryView $history): Response
    {
        $json = json_encode(['history' => $history->forItem($id)], \JSON_THROW_ON_ERROR);
        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        $response->setEtag(md5($json));
        $response->setPublic();
        $response->setMaxAge(60);
        $response->isNotModified($request);

        return $response;
    }
}
