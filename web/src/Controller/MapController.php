<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use App\Catalog\BikeType;
use App\Catalog\CatalogProvider;
use App\Catalog\CatalogSchemaProvider;
use App\Catalog\ChangeHistoryView;
use App\Catalog\RouteRankingService;
use App\Catalog\Season;
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
    public function map(SubmissionQueue $queue, CatalogSchemaProvider $schema): Response
    {
        $params = ['field_schema' => $schema->all()];

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

    /**
     * Best-of ranking for the map's Curated mode (spec §8): ranked verified-route
     * ids for a (season, bike, region?) facet. Public + cacheable like
     * catalog.json; the map flags these ids `cur` and filters Curated to them.
     */
    #[Route('/map/best-of', name: 'map_best_of', methods: ['GET'])]
    public function bestOf(Request $request, RouteRankingService $ranking): Response
    {
        $season = Season::tryFrom((string) $request->query->get('season')) ?? Season::current(new \DateTimeImmutable());
        $bikeParam = (string) $request->query->get('bike', 'all');
        $bike = 'all' === $bikeParam ? null : BikeType::tryFrom($bikeParam);   // invalid → null (all)
        $region = $request->query->has('region') ? $request->query->getInt('region') : null;

        $ids = $ranking->bestOf($season, $bike, $region);
        $json = json_encode([
            'season' => $season->value,
            'bike' => null === $bike ? 'all' : $bike->value,
            'ids' => $ids,
        ], \JSON_THROW_ON_ERROR);

        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        $response->setEtag(md5($json));
        $response->setPublic();
        $response->setMaxAge(300);
        $response->isNotModified($request);

        return $response;
    }
}
