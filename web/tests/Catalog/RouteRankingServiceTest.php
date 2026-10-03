<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\BikeType;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\RouteRankingService;
use App\Catalog\Season;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class RouteRankingServiceTest extends KernelTestCase
{
    private Connection $db;
    private EntityManagerInterface $em;
    private int $user = 5000;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function ranking(string $now = '2027-04-10T12:00:00+00:00'): RouteRankingService
    {
        return new RouteRankingService($this->db, new MockClock(new \DateTimeImmutable($now)));
    }

    /** @param list<string> $bikeTypes */
    private function route(int $regionId = 1, array $bikeTypes = [], ItemState $state = ItemState::Verified): int
    {
        $r = (new RecommendedRoute())->setName('R'.uniqid())
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(20000)->setState($state)
            ->setSource(ItemSource::User)->setSourceRef('user:rank-'.uniqid('', true))->setRegionId($regionId);
        if ([] !== $bikeTypes) {
            $r->setAttributes(['bikeTypes' => $bikeTypes]);
        }
        $this->em->persist($r);
        $this->em->flush();

        return (int) $r->getId();
    }

    private function vote(int $routeId, BikeType $bike, string $season = 'spring', string $start = '2027-03-01', string $at = '2027-04-01 10:00:00', int $slot = 1): void
    {
        $this->db->insert('season_vote', [
            'user_id' => $this->user++, 'region_id' => 1, 'category' => 'quality-rides', 'subject_id' => $routeId,
            'bike_type' => $bike->value, 'season' => $season, 'round_start' => $start, 'slot' => $slot, 'created_at' => $at,
            'submitted_at' => $at,
        ]);
    }

    /** route-domain.md §8d: one first choice (10 points) beats two fifth choices (2 points). */
    public function testRanksByPointsBeforeVoteCount(): void
    {
        $first = $this->route();
        $thirds = $this->route();
        $this->vote($first, BikeType::Road);
        $this->vote($thirds, BikeType::Road, slot: 5);
        $this->vote($thirds, BikeType::Road, slot: 5);

        self::assertSame([$first, $thirds], $this->ranking()->bestOf([Season::Spring], []));
    }

    /** In April riders vote for summer: those votes are on an open ballot and stay off the map. */
    public function testAnOpenBallotNeverReachesTheMap(): void
    {
        $r = $this->route();
        $this->vote($r, BikeType::Road, 'summer', '2027-06-01');

        self::assertSame([], $this->ranking()->bestOf([Season::Summer], []));
        self::assertSame([], $this->ranking()->bestOf([], []));
    }

    public function testRanksByVoteCountThenRecency(): void
    {
        $a = $this->route();
        $b = $this->route();
        $this->vote($a, BikeType::Gravel);
        $this->vote($b, BikeType::Gravel);
        $this->vote($b, BikeType::Gravel);

        self::assertSame([$b, $a], $this->ranking()->bestOf([Season::Spring], [BikeType::Gravel]));
    }

    public function testOnlyTheLatestRoundOfASeasonCounts(): void
    {
        $old = $this->route();
        $now = $this->route();
        $this->vote($old, BikeType::Road, 'spring', '2026-03-01', '2026-04-01 10:00:00');
        $this->vote($now, BikeType::Road);

        self::assertSame([$now], $this->ranking()->bestOf([Season::Spring], []));
    }

    public function testOnALeapDayLastSpringIsStillTheLatestSpring(): void
    {
        $r = $this->route();
        $this->vote($r, BikeType::Road);

        self::assertSame([$r], $this->ranking('2028-02-29T12:00:00+00:00')->bestOf([Season::Spring], []));
    }

    public function testASouthernSpringCountsAsSpring(): void
    {
        $r = $this->route();
        $this->vote($r, BikeType::Road, 'spring', '2026-09-01', '2026-10-01 10:00:00');

        self::assertSame([$r], $this->ranking()->bestOf([Season::Spring], []));
    }

    public function testNoSeasonMeansTheLatestRoundOfEverySeason(): void
    {
        $winter = $this->route();
        $older = $this->route();
        $this->vote($winter, BikeType::Road, 'winter', '2026-12-01', '2027-01-10 10:00:00');
        $this->vote($older, BikeType::Road, 'winter', '2025-12-01', '2026-01-10 10:00:00');

        self::assertSame([$winter], $this->ranking()->bestOf([], []));
    }

    public function testAllBikesAggregatesAcrossBikeTypes(): void
    {
        $r = $this->route();
        $this->vote($r, BikeType::Gravel);
        $this->vote($r, BikeType::Road);

        self::assertSame([$r], $this->ranking()->bestOf([Season::Spring], []));
        self::assertSame([$r], $this->ranking()->bestOf([Season::Spring], [BikeType::Gravel]));
    }

    public function testSpecialtyTypeRequiresDeclaredSuitability(): void
    {
        $suitable = $this->route(1, ['Handbike', 'Gravel']);
        $notDeclared = $this->route(1, ['Gravel']);
        $this->vote($suitable, BikeType::Handbike);
        $this->vote($notDeclared, BikeType::Handbike);

        self::assertSame([$suitable], $this->ranking()->bestOf([Season::Spring], [BikeType::Handbike]));
        $this->vote($notDeclared, BikeType::Gravel);
        self::assertContains($notDeclared, $this->ranking()->bestOf([Season::Spring], [BikeType::Gravel]));
    }

    public function testAnUnverifiedRouteIsNeverListed(): void
    {
        $r = $this->route(1, [], ItemState::Unverified);
        $this->vote($r, BikeType::Road);

        self::assertSame([], $this->ranking()->bestOf([Season::Spring], []));
    }

    public function testEverywhereFacetIsHardCapped(): void
    {
        for ($i = 0; $i <= RouteRankingService::MAX_RESULTS; ++$i) {
            $this->vote($this->route(), BikeType::Gravel);
        }

        self::assertCount(RouteRankingService::MAX_RESULTS, $this->ranking()->bestOf([Season::Spring], [BikeType::Gravel]));
    }

    public function testRegionScoping(): void
    {
        $r1 = $this->route(1);
        $r2 = $this->route(2);
        $r24 = $this->route(24);
        $this->vote($r1, BikeType::Gravel);
        $this->vote($r2, BikeType::Gravel);
        $this->vote($r24, BikeType::Gravel);

        self::assertSame([$r1], $this->ranking()->bestOf([Season::Spring], [], [1]));
        $both = $this->ranking()->bestOf([Season::Spring], [], [1, 24]);
        self::assertContains($r1, $both);
        self::assertContains($r24, $both);
        self::assertNotContains($r2, $both);
    }
}
