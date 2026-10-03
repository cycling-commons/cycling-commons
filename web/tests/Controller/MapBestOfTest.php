<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class MapBestOfTest extends WebTestCase
{
    private int $user = 6000;

    #[\Override]
    protected function setUp(): void
    {
        Clock::set(new MockClock(new \DateTimeImmutable('2027-04-10T12:00:00+00:00')));
    }

    #[\Override]
    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    private function votedRoute(string $season, string $start, string $bike, int $regionId = 1): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $r = (new RecommendedRoute())->setName('BestOf '.uniqid())
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(20000)->setState(ItemState::Verified)
            ->setSource(ItemSource::User)->setSourceRef('user:bo-'.uniqid('', true))->setRegionId($regionId);
        $em->persist($r);
        $em->flush();
        static::getContainer()->get(Connection::class)->insert('season_vote', [
            'user_id' => $this->user++, 'region_id' => $regionId, 'category' => 'quality-rides', 'subject_id' => $r->getId(),
            'bike_type' => $bike, 'season' => $season, 'round_start' => $start, 'slot' => 1, 'created_at' => $start.' 10:00:00',
            'submitted_at' => $start.' 10:05:00',
        ]);

        return (int) $r->getId();
    }

    /** @return array<string, mixed> */
    private static function json(string $body): array
    {
        $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    public function testPublicAndCachedForOneMinute(): void
    {
        $client = static::createClient();
        $r = $this->votedRoute('spring', '2027-03-01', 'Gravel');

        $client->request('GET', '/map/best-of?season=spring&bike=Gravel');
        self::assertResponseIsSuccessful();
        $cache = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cache);
        self::assertStringContainsString('max-age=60', $cache);
        $data = self::json((string) $client->getResponse()->getContent());
        self::assertSame(['spring'], $data['season']);
        self::assertSame(['Gravel'], $data['bike']);
        self::assertContains($r, $data['ids']);
    }

    public function testLastYearsVotesAreNotOnTheMap(): void
    {
        $client = static::createClient();
        $old = $this->votedRoute('spring', '2026-03-01', 'Road');
        $now = $this->votedRoute('spring', '2027-03-01', 'Road');

        $client->request('GET', '/map/best-of?season=spring');
        $data = self::json((string) $client->getResponse()->getContent());
        self::assertContains($now, $data['ids']);
        self::assertNotContains($old, $data['ids']);
    }

    public function testSeveralSeasonsAndBikesRankTogether(): void
    {
        $client = static::createClient();
        $springGravel = $this->votedRoute('spring', '2027-03-01', 'Gravel');
        $winterRoad = $this->votedRoute('winter', '2026-12-01', 'Road');

        $client->request('GET', '/map/best-of?season=spring,winter&bike=Gravel,Road');
        $data = self::json((string) $client->getResponse()->getContent());
        self::assertSame(['spring', 'winter'], $data['season']);
        self::assertSame(['Gravel', 'Road'], $data['bike']);
        self::assertContains($springGravel, $data['ids']);
        self::assertContains($winterRoad, $data['ids']);
    }

    public function testUnknownValuesAreDroppedNotRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map/best-of?season=spring,harvest&bike=Unicycle,all');
        self::assertResponseIsSuccessful();
        $data = self::json((string) $client->getResponse()->getContent());
        self::assertSame(['spring'], $data['season']);
        self::assertSame([], $data['bike']);
    }

    public function testBestOfAcceptsCsvRegionSet(): void
    {
        $client = static::createClient();
        $r1 = $this->votedRoute('summer', '2026-06-01', 'Gravel', 1);
        $r24 = $this->votedRoute('summer', '2026-06-01', 'Gravel', 24);

        $client->request('GET', '/map/best-of?season=summer&region=1');
        $single = $client->getResponse()->getEtag();
        $client->request('GET', '/map/best-of?season=summer&region=1,24,x,999999999999999999999');
        $data = self::json((string) $client->getResponse()->getContent());
        self::assertContains($r1, $data['ids']);
        self::assertContains($r24, $data['ids']);
        self::assertNotSame($single, $client->getResponse()->getEtag());
    }
}
