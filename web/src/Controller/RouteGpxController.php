<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\ItemState;
use App\Contribution\Gpx\GpxWriter;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public GPX download for ACTIVE recommended routes: the track riders ride
 * (and later ride-verify). Serves the stored, privacy-trimmed geometry
 * (route-domain.md §4.3); the untrimmed upload never persisted.
 *
 * @api Instantiated by Symfony's router.
 */
final class RouteGpxController extends AbstractController
{
    #[Route('/routes/{id}.gpx', name: 'route_gpx', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function download(int $id, Connection $db, GpxWriter $writer): Response
    {
        /** @var array{name: string, geom: string}|false $row */
        $row = $db->fetchAssociative(
            'SELECT name, ST_AsGeoJSON(geom) AS geom FROM recommended_route
             WHERE id = :id AND geom IS NOT NULL AND state IN '.ItemState::servedSqlTuple(),
            ['id' => $id],
        );
        if (false === $row) {
            throw $this->createNotFoundException('No active route with that id.');
        }

        $geo = json_decode($row['geom'], true, 512, \JSON_THROW_ON_ERROR);
        // Defensive 404: ingest guarantees a LineString, but a malformed or
        // non-LineString geom must not become a destructuring TypeError.
        if (!\is_array($geo) || 'LineString' !== ($geo['type'] ?? null) || !\is_array($geo['coordinates'] ?? null)) {
            throw $this->createNotFoundException('Route geometry is not a usable track.');
        }

        /** @var list<array{0: float, 1: float}> $coordinates */
        $coordinates = $geo['coordinates'];
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $row['name']), '-')) ?: 'route';

        return new Response($writer->write($row['name'], $coordinates), Response::HTTP_OK, [
            'Content-Type' => 'application/gpx+xml',
            'Content-Disposition' => sprintf('attachment; filename="%s.gpx"', $slug),
            'Cache-Control' => 'public, max-age=3600',
            // Trackpoints only — session-independent; without this the session
            // listener downgrades the caching for any cookie-carrying visitor
            // (frontend review 2026-08-09 #1, same as the /map endpoints).
            AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER => 'true',
        ]);
    }
}
