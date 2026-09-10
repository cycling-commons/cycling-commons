<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
    private function verifiedVotedRoute(EntityManagerInterface $em, Season $s, BikeType $b, int $regionId = 1): RecommendedRoute
    {
        $r = (new RecommendedRoute())->setName('BestOf '.uniqid())
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(20000)->setState(ItemState::Verified)
            ->setSource(ItemSource::User)->setSourceRef('user:bo-'.uniqid())->setRegionId($regionId);
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
        // Both facets echo back as LISTS: the map picks several of each
        // (map-and-search.md §4.0), so a scalar could not describe the request.
        self::assertSame(['spring'], $data['season']);
        self::assertSame(['Gravel'], $data['bike']);
        self::assertContains($r->getId(), $data['ids']);
    }

    public function testSeveralSeasonsAndBikesRankTogether(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $springGravel = $this->verifiedVotedRoute($em, Season::Spring, BikeType::Gravel);
        $autumnRoad = $this->verifiedVotedRoute($em, Season::Autumn, BikeType::Road);

        $client->request('GET', '/map/best-of?season=spring,autumn&bike=Gravel,Road');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(['spring', 'autumn'], $data['season']);
        self::assertSame(['Gravel', 'Road'], $data['bike']);
        // One ranking over the union, not two answers stapled together.
        self::assertContains($springGravel->getId(), $data['ids']);
        self::assertContains($autumnRoad->getId(), $data['ids']);
    }

    public function testEmptyFacetNarrowsByNothing(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        // A vote on a season nobody would guess, so a "defaults to today's
        // season" regression cannot pass this by luck.
        $r = $this->verifiedVotedRoute($em, Season::Winter, BikeType::Tandem);

        $client->request('GET', '/map/best-of');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame([], $data['season'], 'no season param must mean every season');
        self::assertSame([], $data['bike'], 'no bike param must mean every bike');
        self::assertContains($r->getId(), $data['ids']);
    }

    public function testUnknownValuesAreDroppedNotRejected(): void
    {
        // A stale bookmark degrades to a wider answer instead of a 400.
        $client = static::createClient();
        $client->request('GET', '/map/best-of?season=spring,harvest&bike=Unicycle,all');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(['spring'], $data['season']);
        self::assertSame([], $data['bike']);
    }

    public function testBestOfAcceptsCsvRegionSet(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $r1 = $this->verifiedVotedRoute($em, Season::Summer, BikeType::Gravel, 1);
        $r24 = $this->verifiedVotedRoute($em, Season::Summer, BikeType::Gravel, 24);

        $client->request('GET', '/map/best-of?season=summer&bike=Gravel&region=1');
        self::assertResponseIsSuccessful();
        $singleEtag = $client->getResponse()->getEtag();

        $client->request('GET', '/map/best-of?season=summer&bike=Gravel&region=1,24');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertContains($r1->getId(), $data['ids']);
        self::assertContains($r24->getId(), $data['ids']);
        self::assertNotSame($singleEtag, $client->getResponse()->getEtag());
    }

    public function testBestOfRejectsGarbageCsvAsEverywhere(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $r1 = $this->verifiedVotedRoute($em, Season::Summer, BikeType::Gravel, 1);

        // Non-numeric and overflow parts are dropped; the valid id (1) is kept.
        $client->request('GET', '/map/best-of?season=summer&bike=Gravel&region=1,x,999999999999999999999');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertContains($r1->getId(), $data['ids']);
    }
}
