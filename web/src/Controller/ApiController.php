<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Load-balancer probe only — `/api/db-check` was an unauthenticated leak.
 *
 * @see docs/specs/public-api-personal-data-boundary.md §3.3
 *
 * @api
 */
class ApiController
{
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok', 'service' => 'api']);
    }
}
