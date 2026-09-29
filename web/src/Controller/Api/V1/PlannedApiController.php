<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Api\ApiSurface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Every /v1 path no endpoint claims, answered in JSON rather than the site's
 * HTML 404 page.
 *
 * A path the published contract describes is **501, planned**: somebody read
 * openapi.yaml and asked for `/v1/regions`, and a bare 404 told them nothing
 * but "wrong URL" (GlitchTip, 2026-09-28). Anything else is a JSON 404. Both
 * name the paths that answer today and where the contract is, on the main
 * site, because the api host serves `/v1` alone.
 *
 * Named `api_planned`, not `api_v1_*`: {@see ApiSurface::live()} counts that
 * prefix as endpoints that answer.
 *
 * @see docs/specs/public-api.md §2.3
 *
 * @api
 */
final class PlannedApiController extends AbstractController
{
    public function __construct(
        private readonly ApiSurface $surface,
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $siteUrl,
    ) {
    }

    #[Route('/v1/{rest}', name: 'api_planned', requirements: ['rest' => '.+'], methods: ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], priority: -100)]
    public function fallback(Request $request): JsonResponse
    {
        $method = $request->getMethod();
        $path = $request->getPathInfo();
        $planned = $this->surface->describes('HEAD' === $method ? 'GET' : $method, $path);

        return $this->json([
            'error' => $planned ? 'not_implemented' : 'not_found',
            'message' => $planned
                ? sprintf('%s %s is in the published API contract but not built yet.', $method, $path)
                : sprintf('%s %s is not an endpoint of this API.', $method, $path),
            'live' => $this->surface->livePaths(),
            'reference' => rtrim($this->siteUrl, '/').'/developers/api',
            'contract' => rtrim($this->siteUrl, '/').'/api/openapi.yaml',
        ], $planned ? Response::HTTP_NOT_IMPLEMENTED : Response::HTTP_NOT_FOUND);
    }
}
