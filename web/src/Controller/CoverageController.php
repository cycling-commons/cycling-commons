<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Coverage\CoverageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The coverage query plane (coverage-provider.md §5):
 * anonymous, cacheable JSON over the pipeline-owned coverage_poi cache.
 * Site-internal map endpoints, not the Plan 4 public API — the serving cache
 * may return OSM fields with attribution (osm-data-architecture.md §4).
 *
 * @api Instantiated by Symfony's router; called by assets/map/map.js.
 */
final class CoverageController extends AbstractController
{
    /**
     * Drawer detail for a tile POI: display-whitelisted cached OSM tags +
     * curated overlay where a served item shares the ref. 404 for refs the
     * coverage cache does not hold.
     */
    #[Route('/map/coverage/poi/{osmType}/{osmId}', name: 'map_coverage_poi', requirements: ['osmType' => 'node|way', 'osmId' => '\d+'], methods: ['GET'])]
    public function poi(string $osmType, int $osmId, Request $request, CoverageRepository $coverage): Response
    {
        $detail = $coverage->detail($osmType, $osmId);
        if (null === $detail) {
            throw $this->createNotFoundException('No coverage POI.');
        }

        return $this->cacheable($request, $detail, 300);
    }

    /**
     * ETag + public max-age (the MapController::catalog() pattern); encode
     * flags match CatalogProvider::json() so served bytes stay byte-stable.
     *
     * @param array<string, mixed> $payload
     */
    private function cacheable(Request $request, array $payload, int $maxAge): Response
    {
        $json = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        $response->setEtag(md5($json));
        $response->setPublic();
        $response->setMaxAge($maxAge);
        $response->isNotModified($request);

        return $response;
    }
}
