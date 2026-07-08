<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Contribution\Gpx\GpxWriter;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public GPX download for ACTIVE recommended routes (route-domain spec §7):
 * the track riders ride (and later ride-verify). Serves the stored,
 * privacy-trimmed geometry — the untrimmed upload never persisted (D4).
 *
 * @api Instantiated by Symfony's router.
 */
final class RouteGpxController extends AbstractController
{
    /** Mirrors CatalogProvider::SERVED_STATES — only active states are public. */
    private const string SERVED_STATES = "('unverified', 'verified')";

    #[Route('/routes/{id}.gpx', name: 'route_gpx', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function download(int $id, Connection $db, GpxWriter $writer): Response
    {
        /** @var array{name: string, geom: string}|false $row */
        $row = $db->fetchAssociative(
            'SELECT name, ST_AsGeoJSON(geom) AS geom FROM recommended_route
             WHERE id = :id AND state IN '.self::SERVED_STATES,
            ['id' => $id],
        );
        if (false === $row) {
            throw $this->createNotFoundException('No active route with that id.');
        }

        /** @var array{coordinates: list<array{0: float, 1: float}>} $geo */
        $geo = json_decode($row['geom'], true, 512, \JSON_THROW_ON_ERROR);

        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $row['name']), '-')) ?: 'route';

        return new Response($writer->write($row['name'], $geo['coordinates']), Response::HTTP_OK, [
            'Content-Type' => 'application/gpx+xml',
            'Content-Disposition' => sprintf('attachment; filename="%s.gpx"', $slug),
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
