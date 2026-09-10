<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Route-domain spec §7: GET /routes/{id}.gpx — public for ACTIVE routes
 * (unverified + verified), 404 for anything else. You can't ask riders to
 * ride-verify a track they can't download.
 */
final class RouteGpxDownloadTest extends WebTestCase
{
    private function makeRoute(ItemState $state, string $ref): RecommendedRoute
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = (new RecommendedRoute())
            ->setName('Condroz · Namur – Ciney rollers')
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.25,50.45],[5.3,50.5]]}')
            ->setDistanceM(42000)->setAscentM(510)
            ->setState($state)->setSource(ItemSource::User)->setSourceRef($ref);
        $em->persist($route);
        $em->flush();

        return $route;
    }

    public function testActiveRouteDownloadsAsGpx(): void
    {
        $client = static::createClient();
        $route = $this->makeRoute(ItemState::Unverified, 'user:gpx-dl-active');

        $client->request('GET', '/routes/'.$route->getId().'.gpx');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/gpx+xml');
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<gpx', $body);
        self::assertStringContainsString('<trkpt lat="50.4" lon="5.2"', $body, 'GeoJSON [lng,lat] flipped back to GPX lat/lon');
        self::assertStringContainsString('opendatacommons.org/licenses/odbl', $body, 'ODbL attribution present');
        self::assertStringContainsString('Condroz', $body);
    }

    public function testSubmittedProposalIsNotDownloadable(): void
    {
        $client = static::createClient();
        $route = $this->makeRoute(ItemState::Submitted, 'user:gpx-dl-submitted');

        $client->request('GET', '/routes/'.$route->getId().'.gpx');
        self::assertResponseStatusCodeSame(404);
    }

    public function testMissingRouteIs404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/routes/999999.gpx');
        self::assertResponseStatusCodeSame(404);
    }
}
