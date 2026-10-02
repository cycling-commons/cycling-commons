<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Vote;

use App\Catalog\BikeType;
use App\Catalog\Entity\Item;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\ItemType;
use App\Catalog\Season;
use App\Entity\User;
use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;
use App\Vote\BallotCandidates;
use App\Vote\BallotRefused;
use App\Vote\BallotService;
use App\Vote\Hemisphere;
use App\Vote\Round;
use App\Vote\VoterEligibility;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class BallotServiceTest extends KernelTestCase
{
    private const string NOW = '2027-04-10T12:00:00+00:00';

    private Connection $db;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function service(int $live = 1, int $limit = 60): BallotService
    {
        $clock = new MockClock(new \DateTimeImmutable(self::NOW));
        $settings = new class($live) implements SettingsProviderInterface {
            public function __construct(private readonly int $live)
            {
            }

            public function get(string $key): int
            {
                return SettingsRegistry::COMMUNITY_VOTING_LIVE === $key ? $this->live : 0;
            }

            public function getString(string $key): string
            {
                return '';
            }
        };
        $limiter = new RateLimiterFactory(
            ['id' => 'season_vote_test', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );

        return new BallotService($this->db, $clock, $settings, new VoterEligibility($this->db, $clock), new BallotCandidates($this->db), $limiter);
    }

    private function region(string $slug, string $cc, float $lat): int
    {
        $polygon = sprintf('POLYGON((5 %1$F,5 %2$F,6 %2$F,6 %1$F,5 %1$F))', $lat, $lat + 1);
        $this->db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES (?, ?, ST_GeomFromText(?, 4326), 1000, ?, ?, 4, 'test', NOW(), NOW())",
            [$slug, ucfirst($slug), $polygon, $cc, strtoupper(substr($slug, 0, 8))],
        );

        return (int) $this->db->fetchOne('SELECT id FROM region WHERE slug = ?', [$slug]);
    }

    private function item(string $letter, int $regionId, ItemState $state = ItemState::Verified): int
    {
        $item = (new Item())->setLetter($letter)->setName('Place '.bin2hex(random_bytes(3)))
            ->setGeom('{"type":"Point","coordinates":[5.5,50.5]}')->setCountryCode('XA')
            ->setState($state)->setSource(ItemSource::Osm)->setSourceRef('node/'.bin2hex(random_bytes(4)))
            ->setAttributes([])->setRegionId($regionId);
        $this->em->persist($item);
        $this->em->flush();

        return (int) $item->getId();
    }

    /** @param list<string> $bikes the bike types the route declares */
    private function route(int $regionId, ItemState $state = ItemState::Verified, array $bikes = []): int
    {
        $route = (new RecommendedRoute())->setName('Loop '.bin2hex(random_bytes(3)))
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(20000)->setState($state)->setSource(ItemSource::User)
            ->setSourceRef('user:svc-'.bin2hex(random_bytes(6)))->setRegionId($regionId);
        if ([] !== $bikes) {
            $route->setAttributes(['bikeTypes' => $bikes]);
        }
        $this->em->persist($route);
        $this->em->flush();

        return (int) $route->getId();
    }

    private function voter(string $createdAt = '2027-03-01 09:00:00'): User
    {
        $u = (new User())->setEmail('svc-'.bin2hex(random_bytes(4)).'@test.test')->setDisplayName('Voter');
        $u->setEmailVerified(true)->setRoles([])->setPassword('not-a-real-hash');
        $this->em->persist($u);
        $this->em->flush();
        $this->db->executeStatement('UPDATE users SET created_at = ? WHERE id = ?', [$createdAt, $u->getId()]);
        $this->db->executeStatement("INSERT INTO route_ride (route_id, user_id, bike_type, created_at) VALUES (1, ?, 'Road', NOW())", [$u->getId()]);
        $this->em->refresh($u);

        return $u;
    }

    private static function refusal(callable $act): string
    {
        try {
            $act();
        } catch (BallotRefused $e) {
            return $e->reason;
        }
        self::fail('The ballot was expected to refuse.');
    }

    /** @return array<string, mixed> */
    private function row(int $userId, int $subjectId): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM season_vote WHERE user_id = ? AND subject_id = ?', [$userId, $subjectId]);
        self::assertIsArray($row);

        return $row;
    }

    public function testAVoteLandsInTheOpenRoundOfThePlacesRegion(): void
    {
        $rid = $this->region('xa-north', 'XA', 50.0);
        $climb = $this->item('N', $rid);
        $u = $this->voter();

        $round = $this->service()->cast($u, ItemType::Climbs, $climb, BikeType::Road);

        self::assertSame('2027-03-01', $round->startDate());
        $row = $this->row((int) $u->getId(), $climb);
        self::assertSame($rid, (int) $row['region_id']);
        self::assertSame('climbs', $row['category']);
        self::assertSame('spring', $row['season']);
        self::assertSame('2027-03-01', $row['round_start']);
        self::assertSame(1, (int) $row['slot']);
        self::assertNull($row['bike_type'], 'a place vote carries no bike');
    }

    public function testASouthernPlaceVotesInItsOwnAutumn(): void
    {
        $view = $this->item('P', $this->region('xb-south', 'XB', -34.0));
        $u = $this->voter();

        $this->service()->cast($u, ItemType::ScenicViews, $view, null);

        $row = $this->row((int) $u->getId(), $view);
        self::assertSame('autumn', $row['season']);
        self::assertSame('2027-03-01', $row['round_start']);
    }

    public function testARouteVoteNeedsABikeAndKeepsIt(): void
    {
        $route = $this->route($this->region('xa-north', 'XA', 50.0));
        $u = $this->voter();

        self::assertSame(BallotRefused::BIKE_REQUIRED, self::refusal(fn () => $this->service()->cast($u, ItemType::QualityRides, $route, null)));
        $this->service()->cast($u, ItemType::QualityRides, $route, BikeType::Gravel);
        self::assertSame('Gravel', $this->row((int) $u->getId(), $route)['bike_type']);
    }

    /** route-domain.md §8.3: a specialty bike counts only on a route that declares it, so a vote cannot be spent on one that does not. */
    public function testASpecialtyBikeNeedsARouteThatDeclaresIt(): void
    {
        $rid = $this->region('xa-north', 'XA', 50.0);
        $plain = $this->route($rid);
        $suited = $this->route($rid, bikes: ['Road', 'Handbike']);
        $u = $this->voter();
        $s = $this->service();

        self::assertSame(BallotRefused::BIKE_NOT_DECLARED, self::refusal(fn () => $s->cast($u, ItemType::QualityRides, $plain, BikeType::Handbike)));
        self::assertSame(BallotRefused::BIKE_NOT_DECLARED, self::refusal(fn () => $s->cast($u, ItemType::QualityRides, $suited, BikeType::Tandem)));
        $s->cast($u, ItemType::QualityRides, $plain, BikeType::Mtb);
        $s->cast($u, ItemType::QualityRides, $suited, BikeType::Handbike);

        self::assertSame('MTB', $this->row((int) $u->getId(), $plain)['bike_type']);
        self::assertSame('Handbike', $this->row((int) $u->getId(), $suited)['bike_type']);
    }

    public function testTheBallotIsShutWhileVotingIsOff(): void
    {
        $climb = $this->item('N', $this->region('xa-north', 'XA', 50.0));
        $u = $this->voter();
        self::assertSame(BallotRefused::VOTING_CLOSED, self::refusal(fn () => $this->service(live: 0)->cast($u, ItemType::Climbs, $climb, null)));
        self::assertSame(BallotRefused::VOTING_CLOSED, self::refusal(fn () => $this->service(live: 0)->remove($u, ItemType::Climbs, $climb)));
    }

    public function testAnAccountThatCannotVoteYetIsRefused(): void
    {
        $climb = $this->item('N', $this->region('xa-north', 'XA', 50.0));
        $young = $this->voter('2027-04-05 09:00:00');
        self::assertSame(BallotRefused::NOT_ELIGIBLE, self::refusal(fn () => $this->service()->cast($young, ItemType::Climbs, $climb, null)));
    }

    public function testOnlyCatalogueRowsOfTheKindCanGetAVote(): void
    {
        $rid = $this->region('xa-north', 'XA', 50.0);
        $u = $this->voter();
        $s = $this->service();

        self::assertSame(BallotRefused::NOT_CANDIDATE, self::refusal(fn () => $s->cast($u, ItemType::Climbs, $this->item('P', $rid), null)));
        self::assertSame(BallotRefused::NOT_CANDIDATE, self::refusal(fn () => $s->cast($u, ItemType::Climbs, $this->item('N', $rid, ItemState::Retired), null)));
        self::assertSame(BallotRefused::NOT_CANDIDATE, self::refusal(fn () => $s->cast($u, ItemType::QualityRides, $this->route($rid, ItemState::Unverified), BikeType::Road)));
        self::assertSame(BallotRefused::NOT_VOTABLE, self::refusal(fn () => $s->cast($u, ItemType::WaterFood, $this->item('B', $rid), null)));
    }

    public function testThreeVotesPerListAndOnePerItem(): void
    {
        $rid = $this->region('xa-north', 'XA', 50.0);
        $u = $this->voter();
        $s = $this->service();
        [$a, $b, $c, $d] = [$this->item('N', $rid), $this->item('N', $rid), $this->item('N', $rid), $this->item('N', $rid)];

        $s->cast($u, ItemType::Climbs, $a, null);
        self::assertSame(BallotRefused::ALREADY_VOTED, self::refusal(fn () => $s->cast($u, ItemType::Climbs, $a, null)));
        $s->cast($u, ItemType::Climbs, $b, null);
        $s->cast($u, ItemType::Climbs, $c, null);
        self::assertSame(BallotRefused::BALLOT_FULL, self::refusal(fn () => $s->cast($u, ItemType::Climbs, $d, null)));

        // Another category and another region are other lists.
        $s->cast($u, ItemType::ScenicViews, $this->item('P', $rid), null);
        $s->cast($u, ItemType::Climbs, $this->item('N', $this->region('xa-east', 'XA', 51.0)), null);
        self::assertSame(5, (int) $this->db->fetchOne('SELECT COUNT(*) FROM season_vote WHERE user_id = ?', [$u->getId()]));
    }

    public function testARemovedVoteFreesItsSlotForTheNext(): void
    {
        $rid = $this->region('xa-north', 'XA', 50.0);
        $u = $this->voter();
        $s = $this->service();
        [$a, $b, $c, $d] = [$this->item('N', $rid), $this->item('N', $rid), $this->item('N', $rid), $this->item('N', $rid)];
        $s->cast($u, ItemType::Climbs, $a, null);
        $s->cast($u, ItemType::Climbs, $b, null);
        $s->cast($u, ItemType::Climbs, $c, null);

        self::assertTrue($s->remove($u, ItemType::Climbs, $b));
        $s->cast($u, ItemType::Climbs, $d, null);

        self::assertSame(2, (int) $this->row((int) $u->getId(), $d)['slot']);
        self::assertSame([$a => null, $d => null, $c => null], $s->mine($u, ItemType::Climbs, $rid, Round::of(Season::Spring, 2027, Hemisphere::North)));
    }

    public function testRemovingTouchesOnlyTheOpenRound(): void
    {
        $rid = $this->region('xa-north', 'XA', 50.0);
        $climb = $this->item('N', $rid);
        $u = $this->voter();
        $this->db->insert('season_vote', [
            'user_id' => $u->getId(), 'region_id' => $rid, 'category' => 'climbs', 'subject_id' => $climb,
            'bike_type' => null, 'season' => 'spring', 'round_start' => '2026-03-01', 'slot' => 1, 'created_at' => '2026-04-01 10:00:00',
        ]);
        $s = $this->service();
        $s->cast($u, ItemType::Climbs, $climb, null);

        self::assertTrue($s->remove($u, ItemType::Climbs, $climb));
        self::assertFalse($s->remove($u, ItemType::Climbs, $climb));
        self::assertSame(['2026-03-01'], $this->db->fetchFirstColumn('SELECT round_start FROM season_vote WHERE user_id = ?', [$u->getId()]));
    }

    public function testRemovingLeavesAnotherRidersVoteInPlace(): void
    {
        $climb = $this->item('N', $this->region('xa-north', 'XA', 50.0));
        [$mine, $theirs] = [$this->voter(), $this->voter()];
        $s = $this->service();
        $s->cast($mine, ItemType::Climbs, $climb, null);
        $s->cast($theirs, ItemType::Climbs, $climb, null);

        self::assertTrue($s->remove($mine, ItemType::Climbs, $climb));

        self::assertSame([(int) $theirs->getId()], array_map(intval(...), $this->db->fetchFirstColumn('SELECT user_id FROM season_vote WHERE subject_id = ?', [$climb])));
    }

    public function testTheLimiterStopsAFlood(): void
    {
        $climb = $this->item('N', $this->region('xa-north', 'XA', 50.0));
        $u = $this->voter();
        $s = $this->service(limit: 2);

        $s->cast($u, ItemType::Climbs, $climb, null);
        $s->remove($u, ItemType::Climbs, $climb);
        self::assertSame(BallotRefused::RATE_LIMITED, self::refusal(fn () => $s->cast($u, ItemType::Climbs, $climb, null)));
    }
}
