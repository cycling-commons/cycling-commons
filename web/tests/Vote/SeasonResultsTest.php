<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Vote;

use App\Catalog\BikeType;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\ItemType;
use App\Catalog\Season;
use App\Vote\BallotCandidates;
use App\Vote\Hemisphere;
use App\Vote\ListKey;
use App\Vote\Round;
use App\Vote\SeasonResults;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class SeasonResultsTest extends KernelTestCase
{
    private const int REGION = 900001;

    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
    }

    private function results(string $now): SeasonResults
    {
        return new SeasonResults($this->db, new MockClock(new \DateTimeImmutable($now)), new BallotCandidates($this->db));
    }

    private function vote(int $user, int $subject, string $start, string $category = 'climbs', ?string $bike = null, string $season = 'spring', bool $submitted = true): void
    {
        $slot = 1 + (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM season_vote WHERE user_id = ? AND region_id = ? AND category = ? AND round_start = ?',
            [$user, self::REGION, $category, $start],
        );
        $this->db->insert('season_vote', [
            'user_id' => $user, 'region_id' => self::REGION, 'category' => $category, 'subject_id' => $subject,
            'bike_type' => $bike, 'season' => $season, 'round_start' => $start, 'slot' => $slot, 'created_at' => $start.' 10:00:00',
            'submitted_at' => $submitted ? $start.' 10:05:00' : null,
        ]);
    }

    /** @param array<int, list<int>> $ballots user => subjects */
    private function ballots(array $ballots, string $start, string $category = 'climbs', ?string $bike = null): void
    {
        foreach ($ballots as $user => $subjects) {
            foreach ($subjects as $subject) {
                $this->vote($user, $subject, $start, $category, $bike);
            }
        }
    }

    private static function spring(int $year): Round
    {
        return Round::of(Season::Spring, $year, Hemisphere::North);
    }

    /**
     * @param list<array{subjectId: int, place: ?int}> $entries
     *
     * @return array<int, ?int>
     */
    private static function places(array $entries): array
    {
        $out = [];
        foreach ($entries as $e) {
            $out[$e['subjectId']] = $e['place'];
        }

        return $out;
    }

    public function testTheWorkedExampleWithLastYearsWinner(): void
    {
        [$a, $b, $c, $d, $x] = [1001, 1002, 1003, 1004, 1005];
        // Spring 2027, five voters: A first, X second, D third.
        $this->ballots([1 => [$a, $x, $d], 2 => [$a, $x, $d], 3 => [$a, $x, $d], 4 => [$a, $x], 5 => [$a]], '2027-03-01');
        // Spring 2028, seven ranked ballots (10, 7, 5, 3, 1 points): A 40, B 30, C 19, D 31.
        $this->ballots([1 => [$a, $b], 2 => [$a, $d, $c, $b], 3 => [$a, $c], 4 => [$a, $d], 5 => [$b, $c], 6 => [$b, $d], 7 => [$d]], '2028-03-01');

        $list = $this->results('2028-04-10T12:00:00+00:00')->list(new ListKey(self::REGION, ItemType::Climbs), self::spring(2028));

        self::assertSame(7, $list['voters']);
        self::assertTrue($list['ranked']);
        self::assertTrue($list['closed']);
        // A 40 x 0.75 = 30 and B 30 share first, B first because A won before;
        // D 31 x 0.75 = 23.25 is third, C 19 fourth. Scores in quarter points.
        self::assertSame([$b, $a, $d, $c], array_column($list['entries'], 'subjectId'));
        self::assertSame([$b => 1, $a => 1, $d => 3, $c => 4], self::places($list['entries']));
        self::assertSame([120, 120, 93, 76], array_column($list['entries'], 'score'));
        self::assertSame([false, true, true, false], array_column($list['entries'], 'handicapped'));
        self::assertSame([0, 1, 0, 0], array_column($list['entries'], 'winsBefore'));
        // Reading 2028 stored the closed spring 2027 it needed.
        self::assertSame(3, (int) $this->db->fetchOne("SELECT COUNT(*) FROM season_result WHERE region_id = ? AND round_start = '2027-03-01'", [self::REGION]));
    }

    public function testWinsBeforeCountSharedFirstPlacesInAnySeason(): void
    {
        // Summer 2027: 1001 and 1002 share first place, 13 points each.
        foreach ([1 => [1001, 1002], 2 => [1002, 1001], 3 => [1001], 4 => [1002], 5 => [1003]] as $user => $subjects) {
            foreach ($subjects as $subject) {
                $this->vote($user, $subject, '2027-06-01', season: 'summer');
            }
        }
        // Autumn 2027: 1001 wins alone.
        foreach ([1, 2, 3, 4, 5] as $user) {
            $this->vote($user, 1001, '2027-09-01', season: 'autumn');
        }
        // Spring 2028: three items share first place, each ranked first, second and third twice.
        $this->ballots([
            1 => [1001, 1002, 1003], 2 => [1002, 1003, 1001], 3 => [1003, 1001, 1002],
            4 => [1001, 1002, 1003], 5 => [1002, 1003, 1001], 6 => [1003, 1001, 1002],
        ], '2028-03-01');

        $list = $this->results('2028-04-10T12:00:00+00:00')->list(new ListKey(self::REGION, ItemType::Climbs), self::spring(2028));

        self::assertSame([1003, 1002, 1001], array_column($list['entries'], 'subjectId'));
        self::assertSame([0, 1, 2], array_column($list['entries'], 'winsBefore'));
        self::assertSame([1003 => 1, 1002 => 1, 1001 => 1], self::places($list['entries']));
    }

    public function testEveryItemPlacedOneToThreeLastYearIsHandicapped(): void
    {
        // Spring 2027 places: 1001 first, 1002 second, 1003 and 1004 share third (2 points each), 1005 fifth (1 point).
        $this->ballots([1 => [1001, 1002, 1003], 2 => [1001, 1002, 1003], 3 => [1001, 1002, 1004], 4 => [1001, 1002, 1004], 5 => [1001, 1002, 1005]], '2027-03-01');
        $this->ballots([1 => [1001, 1002, 1003], 2 => [1004, 1005]], '2028-03-01');

        $results = $this->results('2028-04-10T12:00:00+00:00');
        $key = new ListKey(self::REGION, ItemType::Climbs);
        $last = $results->list($key, self::spring(2027));
        $list = $results->list($key, self::spring(2028));

        self::assertSame([1001 => 1, 1002 => 2, 1003 => 3, 1004 => 3, 1005 => 5], self::places($last['entries']));
        $handicapped = array_column($list['entries'], 'handicapped', 'subjectId');
        ksort($handicapped);
        self::assertSame([1001 => true, 1002 => true, 1003 => true, 1004 => true, 1005 => false], $handicapped);
    }

    public function testAListBelowFiveVotersHasNoRanking(): void
    {
        $this->ballots([1 => [1001, 1002], 2 => [1001], 3 => [1002], 4 => [1001]], '2027-03-01');

        $list = $this->results('2027-04-10T12:00:00+00:00')->list(new ListKey(self::REGION, ItemType::Climbs), self::spring(2027));

        self::assertSame(4, $list['voters']);
        self::assertFalse($list['ranked']);
        self::assertSame([1001 => null, 1002 => null], self::places($list['entries']));
    }

    public function testNoHandicapWhenLastYearNeverReachedFiveVoters(): void
    {
        $this->ballots([1 => [1001], 2 => [1001], 3 => [1001], 4 => [1001]], '2027-03-01');
        $this->ballots([1 => [1001], 2 => [1001], 3 => [1002], 4 => [1002], 5 => [1001]], '2028-03-01');

        $list = $this->results('2028-04-10T12:00:00+00:00')->list(new ListKey(self::REGION, ItemType::Climbs), self::spring(2028));

        self::assertSame([false, false], array_column($list['entries'], 'handicapped'));
        self::assertSame([1001 => 1, 1002 => 2], self::places($list['entries']));
    }

    public function testAClosedRoundIsStoredOnceAndNeverChanges(): void
    {
        $this->ballots([1 => [1001], 2 => [1001], 3 => [1001], 4 => [1002], 5 => [1002]], '2027-03-01');
        $results = $this->results('2027-07-01T12:00:00+00:00');
        $key = new ListKey(self::REGION, ItemType::Climbs);

        $first = $results->list($key, self::spring(2027));
        $this->db->executeStatement('DELETE FROM season_vote WHERE user_id IN (1, 2)');
        $again = $results->list($key, self::spring(2027));

        self::assertTrue($first['closed']);
        self::assertSame($first, $again);
        self::assertSame(5, $again['voters']);
        self::assertSame([1001 => 1, 1002 => 2], self::places($again['entries']));
    }

    /** In the hour after the ballot closes the list is counted for the page, and not stored yet. */
    public function testAListInItsGraceHourIsCountedAndNotStored(): void
    {
        $this->ballots([1 => [1001]], '2027-03-01');

        $list = $this->results('2027-03-01T00:30:00+00:00')->list(new ListKey(self::REGION, ItemType::Climbs), self::spring(2027));

        self::assertTrue($list['closed']);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM season_result WHERE region_id = ?', [self::REGION]));
    }

    /** The grace hour ends exactly an hour after the ballot closes: at that second the list is stored. */
    public function testAListIsStoredTheMomentItsGraceHourEnds(): void
    {
        $this->ballots([1 => [1001]], '2027-03-01');

        $list = $this->results('2027-03-01T01:00:00+00:00')->list(new ListKey(self::REGION, ItemType::Climbs), self::spring(2027));

        self::assertTrue($list['closed']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM season_result WHERE region_id = ?', [self::REGION]));
    }

    /** route-domain.md §8c: while riders still vote, the list shows nothing, so no standing can be read off it. */
    public function testTheSpringListIsHiddenUntilItsBallotCloses(): void
    {
        $this->ballots([1 => [1001], 2 => [1001], 3 => [1002], 4 => [1001], 5 => [1002]], '2027-03-01');

        $list = $this->results('2027-02-28T23:30:00+00:00')->list(new ListKey(self::REGION, ItemType::Climbs), self::spring(2027));

        self::assertSame(['voters' => 0, 'ranked' => false, 'closed' => false, 'entries' => []], $list);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM season_result WHERE region_id = ?', [self::REGION]));
    }

    public function testFreezingLeavesAnOpenBallotAlone(): void
    {
        $this->ballots([1 => [1001]], '2027-03-01');
        $this->ballots([1 => [1001]], '2027-06-01');

        $stored = $this->results('2027-04-10T12:00:00+00:00')->freezeEveryClosed();

        self::assertSame(1, $stored);
        self::assertSame(['2027-03-01'], $this->db->fetchFirstColumn('SELECT DISTINCT round_start FROM season_result WHERE region_id = ?', [self::REGION]));
    }

    public function testABikeListCountsOnlyThatBikesVoters(): void
    {
        $this->ballots([1 => [2001], 2 => [2001], 3 => [2002], 4 => [2002], 5 => [2001]], '2027-03-01', 'quality-rides', 'Gravel');
        $this->ballots([6 => [2001], 7 => [2002]], '2027-03-01', 'quality-rides', 'Road');
        $results = $this->results('2027-04-10T12:00:00+00:00');

        $gravel = $results->list(new ListKey(self::REGION, ItemType::QualityRides, BikeType::Gravel), self::spring(2027));
        $road = $results->list(new ListKey(self::REGION, ItemType::QualityRides, BikeType::Road), self::spring(2027));
        $all = $results->list(new ListKey(self::REGION, ItemType::QualityRides), self::spring(2027));

        self::assertSame([5, true], [$gravel['voters'], $gravel['ranked']]);
        self::assertSame([2, false], [$road['voters'], $road['ranked']]);
        self::assertSame(7, $all['voters']);
    }

    public function testASpecialtyListHoldsOnlyRoutesDeclaredForThatBike(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $ids = [];
        foreach ([['Handbike', 'Road'], ['Road']] as $declared) {
            $route = (new RecommendedRoute())->setName('Spec '.bin2hex(random_bytes(3)))
                ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
                ->setDistanceM(20000)->setState(ItemState::Verified)->setSource(ItemSource::User)
                ->setSourceRef('user:spec-'.bin2hex(random_bytes(6)))->setRegionId(self::REGION)
                ->setAttributes(['bikeTypes' => $declared]);
            $em->persist($route);
            $em->flush();
            $ids[] = (int) $route->getId();
        }
        $this->ballots([1 => $ids, 2 => $ids], '2027-03-01', 'quality-rides', 'Handbike');

        $list = $this->results('2027-04-10T12:00:00+00:00')->list(new ListKey(self::REGION, ItemType::QualityRides, BikeType::Handbike), self::spring(2027));

        self::assertSame([$ids[0]], array_column($list['entries'], 'subjectId'));
    }

    public function testTheHandicapFollowsTheNarrowedList(): void
    {
        // 2001 won spring 2027 on road bikes only.
        $this->ballots([1 => [2001], 2 => [2001], 3 => [2001], 4 => [2001], 5 => [2001]], '2027-03-01', 'quality-rides', 'Road');
        $this->ballots([1 => [2001], 2 => [2001], 3 => [2002], 4 => [2002], 5 => [2001]], '2028-03-01', 'quality-rides', 'Gravel');
        $results = $this->results('2028-04-10T12:00:00+00:00');

        $gravel = $results->list(new ListKey(self::REGION, ItemType::QualityRides, BikeType::Gravel), self::spring(2028));

        self::assertSame([false, false], array_column($gravel['entries'], 'handicapped'));
    }

    /** route-domain.md §8c: a ballot never submitted counts for nothing. */
    public function testADraftBallotIsNotCounted(): void
    {
        $this->ballots([1 => [1001], 2 => [1001], 3 => [1001], 4 => [1002], 5 => [1002]], '2027-03-01');
        $this->vote(6, 1003, '2027-03-01', submitted: false);
        $this->vote(6, 1002, '2027-03-01', submitted: false);

        $list = $this->results('2027-04-10T12:00:00+00:00')->list(new ListKey(self::REGION, ItemType::Climbs), self::spring(2027));

        self::assertSame(5, $list['voters']);
        self::assertSame([1001, 1002], array_column($list['entries'], 'subjectId'));
    }

    public function testDraftsAreDeletedOnceTheirBallotCloses(): void
    {
        $this->vote(1, 1001, '2027-03-01');                       // submitted, closed: kept
        $this->vote(2, 1001, '2027-03-01', submitted: false);     // draft, closed: deleted
        $this->vote(3, 1001, '2027-06-01', season: 'summer', submitted: false); // draft, still open: kept

        $dropped = $this->results('2027-04-10T12:00:00+00:00')->dropClosedDrafts();

        self::assertSame(1, $dropped);
        self::assertSame([1, 3], array_map(intval(...), $this->db->fetchFirstColumn('SELECT user_id FROM season_vote WHERE region_id = ? ORDER BY user_id', [self::REGION])));
    }
}
