<?php
// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-scaffold endpoints. These exist to prove the wiring works end to end;
 * the real query / contribution / moderation routes get built on top.
 */
class ApiController
{
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok', 'service' => 'api']);
    }

    #[Route('/api/db-check', name: 'db_check', methods: ['GET'])]
    public function dbCheck(Connection $db): JsonResponse
    {
        try {
            $postgres = $db->fetchOne('SELECT version()');
            $postgis = $db->fetchOne('SELECT postgis_full_version()');
        } catch (\Throwable $e) {
            return new JsonResponse(
                ['status' => 'error', 'message' => $e->getMessage()],
                JsonResponse::HTTP_SERVICE_UNAVAILABLE
            );
        }

        return new JsonResponse([
            'status' => 'ok',
            'postgres' => $postgres,
            'postgis' => $postgis,
        ]);
    }
}
