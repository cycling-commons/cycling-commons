<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\RouteTrack;
use App\Contribution\Gpx\GpxWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public GPX of served routes — privacy-trimmed geometry only.
 *
 * @see docs/specs/route-domain.md §4.3
 *
 * @api
 */
final class RouteGpxController extends AbstractController
{
    #[Route('/routes/{id}.gpx', name: 'route_gpx', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function download(int $id, RouteTrack $tracks, GpxWriter $writer): Response
    {
        $track = $tracks->find($id);
        if (null === $track) {
            throw $this->createNotFoundException('No active route with that id.');
        }

        return new Response($writer->write($track['name'], $track['coordinates']), Response::HTTP_OK, [
            'Content-Type' => 'application/gpx+xml',
            'Content-Disposition' => sprintf('attachment; filename="%s.gpx"', RouteTrack::fileName($track['name'])),
            'Cache-Control' => 'public, max-age=3600',
            // docs/specs/account-and-auth.md §5 — public cache; do not let a session cookie downgrade it.
            AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER => 'true',
        ]);
    }
}
