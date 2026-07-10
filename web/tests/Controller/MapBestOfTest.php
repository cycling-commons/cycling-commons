<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Catalog\BikeType;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteVote;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\Season;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MapBestOfTest extends WebTestCase
{
    private function verifiedVotedRoute(EntityManagerInterface $em, Season $s, BikeType $b): RecommendedRoute
    {
        $r = (new RecommendedRoute())->setName('BestOf '.uniqid())
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(20000)->setState(ItemState::Verified)
            ->setSource(ItemSource::User)->setSourceRef('user:bo-'.uniqid())->setRegionId(1);
        $em->persist($r);
        $em->flush();
        $em->persist(new RouteVote($r->getId(), 10, $s, $b));
        $em->flush();

        return $r;
    }

    public function testPublicAndReturnsRankedIds(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $r = $this->verifiedVotedRoute($em, Season::Spring, BikeType::Gravel);

        // No login — endpoint is public (like catalog.json).
        $client->request('GET', '/map/best-of?season=spring&bike=Gravel');
        self::assertResponseIsSuccessful();
        self::assertResponseHasHeader('Cache-Control');
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('spring', $data['season']);
        self::assertSame('Gravel', $data['bike']);
        self::assertContains($r->getId(), $data['ids']);
    }

    public function testDefaultsToCurrentSeasonAllBikes(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $current = Season::current(new \DateTimeImmutable());
        $r = $this->verifiedVotedRoute($em, $current, BikeType::Road);

        $client->request('GET', '/map/best-of');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($current->value, $data['season']);
        self::assertSame('all', $data['bike']);
        self::assertContains($r->getId(), $data['ids']);
    }

    public function testInvalidBikeFallsBackToAll(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map/best-of?season=spring&bike=Unicycle');
        self::assertResponseIsSuccessful();
        self::assertSame('all', json_decode((string) $client->getResponse()->getContent(), true)['bike']);
    }
}
