<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Vote;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use App\Settings\SettingsRegistry;
use App\Settings\SystemSettingsWriter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * /best once `community.voting_live` is on: the same page, filled with the
 * season ballot's real lists (route-domain.md §8d).
 */
final class BestOfLiveTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private EntityManagerInterface $em;
    private int $user = 7000;

    protected function setUp(): void
    {
        Clock::set(new MockClock(new \DateTimeImmutable('2027-04-10T12:00:00+00:00')));
        $this->client = static::createClient();
        $this->db = static::getContainer()->get(Connection::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        static::getContainer()->get(SystemSettingsWriter::class)->set(SettingsRegistry::COMMUNITY_VOTING_LIVE, 1, null);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    private function region(string $slug, string $name, float $lat = 50.0): int
    {
        $polygon = sprintf('POLYGON((5 %1$F,5 %2$F,6 %2$F,6 %1$F,5 %1$F))', $lat, $lat + 1);
        $this->db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES (?, ?, ST_GeomFromText(?, 4326), 1000, 'XA', ?, 4, 'test', NOW(), NOW())",
            [$slug, $name, $polygon, strtoupper(substr($slug, 0, 8))],
        );

        return (int) $this->db->fetchOne('SELECT id FROM region WHERE slug = ?', [$slug]);
    }

    private function climb(int $regionId, string $name): Item
    {
        $item = (new Item())->setLetter('N')->setName($name)
            ->setGeom('{"type":"Point","coordinates":[5.5,50.5]}')->setCountryCode('XA')
            ->setState(ItemState::Verified)->setSource(ItemSource::Osm)->setSourceRef('node/'.bin2hex(random_bytes(4)))
            ->setAttributes([])->setRegionId($regionId);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    private function route(int $regionId, string $name, int $metres): int
    {
        $route = (new RecommendedRoute())->setName($name)
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM($metres)->setState(ItemState::Verified)->setSource(ItemSource::User)
            ->setSourceRef('user:best-'.bin2hex(random_bytes(6)))->setRegionId($regionId);
        $this->em->persist($route);
        $this->em->flush();

        return (int) $route->getId();
    }

    /** @param list<int> $subjects one voter each, every voter votes for all of them */
    private function voters(int $regionId, int $count, array $subjects, string $category = 'climbs', ?string $bike = null, string $season = 'spring', string $roundStart = '2027-03-01'): void
    {
        for ($v = 0; $v < $count; ++$v) {
            $user = $this->user++;
            foreach ($subjects as $slot => $subject) {
                $this->db->insert('season_vote', [
                    'user_id' => $user, 'region_id' => $regionId, 'category' => $category, 'subject_id' => $subject,
                    'bike_type' => $bike, 'season' => $season, 'round_start' => $roundStart, 'slot' => $slot + 1,
                    'created_at' => $roundStart.' 10:00:00', 'submitted_at' => $roundStart.' 10:05:00',
                ]);
            }
        }
    }

    /** @return list<string> the region headings of the page, top to bottom */
    private static function headings(\Symfony\Component\DomCrawler\Crawler $crawler): array
    {
        return $crawler->filter('.rgroup h2')->each(static fn ($n): string => trim($n->text()));
    }

    public function testARankedListShowsItsPlacesAndASharedFirst(): void
    {
        $rid = $this->region('xa-ranked', 'Ranked Hills');
        $a = (int) $this->climb($rid, 'Col A')->getId();
        $b = (int) $this->climb($rid, 'Col B')->getId();
        $c = (int) $this->climb($rid, 'Col C')->getId();
        // A and B 75 points each (15 + 10 from three riders each way), C 15.
        $this->voters($rid, 3, [$a, $b]);
        $this->voters($rid, 3, [$b, $a]);
        $this->voters($rid, 1, [$c]);

        $crawler = $this->client->request('GET', '/best?cc=XA');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.prev');
        $group = $crawler->filter('.rgroup')->reduce(static fn ($n): bool => str_contains($n->text(), 'Ranked Hills'));
        self::assertCount(1, $group);
        self::assertStringNotContainsString('Spring 2027', $group->text(), 'the title names the round, not each region (owner 2026-10-03)');
        self::assertCount(0, $group->filter('.nr-chip'), 'a ranked list says nothing about a ranking it has');
        self::assertStringNotContainsString('Vote for next season', $group->text(), 'one way to the ballot, the button beside the filters');
        self::assertStringNotContainsString('voters', $group->text(), 'no voter count in public (route-domain.md §8c)');
        self::assertStringNotContainsString('votes', $group->text(), 'no vote count in public');
        self::assertSame(['1=', '1=', '3'], $group->filter('ol.rank .n')->each(static fn ($n): string => trim($n->text())));
        // A closed round shows its points (owner 2026-10-03): 3 x 15 + 3 x 10 each, and one first choice.
        self::assertSame(['75 points', '75 points', '15 points'], $group->filter('ol.rank .pts')->each(static fn ($n): string => trim($n->text())));
        self::assertStringNotContainsString('Lorem ipsum', $group->text(), 'a real card carries no filler');
    }

    public function testAListBelowFiveVotersSaysNoRankingYet(): void
    {
        $rid = $this->region('xa-quiet', 'Quiet Vale');
        $a = (int) $this->climb($rid, 'Col Quiet')->getId();
        $this->voters($rid, 2, [$a]);

        $crawler = $this->client->request('GET', '/best?cc=XA');

        $group = $crawler->filter('.rgroup')->reduce(static fn ($n): bool => str_contains($n->text(), 'Quiet Vale'));
        self::assertStringContainsString('No ranking yet', $group->text());
        self::assertStringNotContainsString('voters', $group->text());
        self::assertStringContainsString('Col Quiet', $group->text());
        self::assertCount(0, $group->filter('ol.pod3'), 'no podium before the threshold');
    }

    public function testEverywhereListsOnlyRankedLists(): void
    {
        $ranked = $this->region('xa-ranked', 'Ranked Hills');
        $quiet = $this->region('xa-quiet', 'Quiet Vale');
        $this->voters($ranked, 5, [(int) $this->climb($ranked, 'Col A')->getId()]);
        $this->voters($quiet, 2, [(int) $this->climb($quiet, 'Col Quiet')->getId()]);

        $this->client->request('GET', '/best');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Ranked Hills', $html);
        self::assertStringNotContainsString('Quiet Vale', $html);
    }

    public function testAPlaceRetiredMidRoundKeepsItsPlaceWithoutAMapLink(): void
    {
        $rid = $this->region('xa-ranked', 'Ranked Hills');
        $gone = $this->climb($rid, 'Col Gone');
        $this->voters($rid, 5, [(int) $gone->getId()]);
        $gone->setState(ItemState::Retired);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/best?cc=XA');

        self::assertSelectorTextContains('.rgroup', 'Col Gone');
        self::assertSelectorTextContains('.rgroup', 'No longer on the map');
        self::assertCount(0, $crawler->filter('.rgroup a[href*="item='.$gone->getId().'"]'));
    }

    /** Length only hides rows: the shown route keeps the place it holds in the whole list. */
    public function testARouteFilterHidesRowsAndKeepsPlaces(): void
    {
        $rid = $this->region('xa-routes', 'Route Hills');
        $long = $this->route($rid, 'Long Loop', 160_000);
        $short = $this->route($rid, 'Short Loop', 20_000);
        $this->voters($rid, 5, [$long], 'quality-rides', 'Road');
        $this->voters($rid, 3, [$short], 'quality-rides', 'Road');

        $crawler = $this->client->request('GET', '/best?cat=quality-rides&len=Short&cc=XA');

        self::assertResponseIsSuccessful();
        $group = $crawler->filter('.rgroup')->reduce(static fn ($n): bool => str_contains($n->text(), 'Route Hills'));
        self::assertStringNotContainsString('Long Loop', $group->text());
        self::assertSame(['2'], $group->filter('ol.rank .n')->each(static fn ($n): string => trim($n->text())));
    }

    /**
     * Twelve lists at most, busiest first, and the thirteenth busiest is never
     * computed. A closed round is stored the first time its list is read, so
     * a stored row for a region proves the page read its list.
     */
    public function testEverywhereComputesOnlyTheTwelveBusiestLists(): void
    {
        $ids = [];
        for ($i = 0; $i < 13; ++$i) {
            $rid = $this->region(sprintf('xa-b%02d', $i), sprintf('Busy %02d', $i));
            $ids[$i] = $rid;
            // Busy 00 has 5 voters, Busy 12 has 17: all ranked.
            $this->voters($rid, 5 + $i, [(int) $this->climb($rid, sprintf('Col %02d', $i))->getId()], 'climbs', null, 'winter', '2026-12-01');
        }

        $crawler = $this->client->request('GET', '/best?season=winter');

        self::assertResponseIsSuccessful();
        $expected = array_map(static fn (int $i): string => sprintf('Busy %02d', $i), range(12, 1));
        self::assertSame($expected, array_values(array_filter(self::headings($crawler), static fn (string $h): bool => str_starts_with($h, 'Busy '))));
        $stored = array_map(intval(...), $this->db->fetchFirstColumn('SELECT DISTINCT region_id FROM season_result WHERE region_id IN (?)', [array_values($ids)], [\Doctrine\DBAL\ArrayParameterType::INTEGER]));
        sort($stored);
        self::assertSame(\array_slice(array_values($ids), 1), $stored, 'the least busy list was never read');
    }

    /** A region the route filters emptied is named apart from one with nothing on the map, even when no list is left. */
    public function testFilteredRegionsAreNotCalledEmpty(): void
    {
        $rid = $this->region('xa-routes', 'Route Hills');
        $this->voters($rid, 5, [$this->route($rid, 'Long Loop', 160_000)], 'quality-rides', 'Road');
        $this->region('xa-empty', 'Empty Vale', 52.0);

        $crawler = $this->client->request('GET', '/best?cat=quality-rides&len=Short&cc=XA');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.rgroup'));
        $quiet = $crawler->filter('section.quiet');
        self::assertCount(2, $quiet);
        self::assertStringContainsString('Nothing in these regions matches these filters', $quiet->eq(0)->text());
        self::assertStringContainsString('Route Hills', $quiet->eq(0)->text());
        self::assertStringNotContainsString('Empty Vale', $quiet->eq(0)->text());
        self::assertStringContainsString('Nothing to vote for yet', $quiet->eq(1)->text());
        self::assertStringContainsString('Empty Vale', $quiet->eq(1)->text());
        self::assertStringNotContainsString('Route Hills', $quiet->eq(1)->text());
        self::assertStringNotContainsString('Nobody has voted here yet', (string) $this->client->getResponse()->getContent());
    }

    /**
     * A region with nothing to vote for, and one whose places have no vote in
     * this round, read no list. A closed round is stored the first time its
     * list is read, so their unstored spring 2026 proves the page never read
     * one (app:vote:freeze stores it, not a page view).
     */
    public function testRegionsWithNoVoteThisRoundReadNoList(): void
    {
        $empty = $this->region('xa-empty', 'Empty Vale');
        $gone = $this->climb($empty, 'Col Gone');
        $this->voters($empty, 5, [(int) $gone->getId()], 'climbs', null, 'spring', '2026-03-01');
        $gone->setState(ItemState::Retired);
        $this->em->flush();
        $idle = $this->region('xa-idle', 'Idle Hills', 52.0);
        $this->voters($idle, 5, [(int) $this->climb($idle, 'Col Idle')->getId()], 'climbs', null, 'spring', '2026-03-01');

        $crawler = $this->client->request('GET', '/best?cc=XA');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Empty Vale', $crawler->filter('section.quiet')->text());
        $group = $crawler->filter('.rgroup')->reduce(static fn ($n): bool => str_contains($n->text(), 'Idle Hills'));
        self::assertStringContainsString('No ranking yet', $group->text());
        self::assertStringNotContainsString('voters', $group->text());
        self::assertStringContainsString('Col Idle', $group->text());
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM season_result WHERE region_id IN (?, ?)', [$empty, $idle]));
    }

    /** Below the threshold the length filter narrows the candidates before the list is cut, not after. */
    public function testARouteFilterFindsAMatchBelowTheMostConfirmed(): void
    {
        $rid = $this->region('xa-routes', 'Route Hills');
        for ($i = 0; $i < 31; ++$i) {
            $long = $this->route($rid, sprintf('Long %02d', $i), 160_000);
            $this->db->executeStatement("INSERT INTO route_ride (route_id, user_id, bike_type, created_at) VALUES (?, ?, 'Road', NOW())", [$long, 8000 + $i]);
        }
        $this->route($rid, 'Short Loop', 20_000);

        $crawler = $this->client->request('GET', '/best?cat=quality-rides&len=Short&cc=XA');

        self::assertResponseIsSuccessful();
        $group = $crawler->filter('.rgroup')->reduce(static fn ($n): bool => str_contains($n->text(), 'Route Hills'));
        self::assertCount(1, $group);
        self::assertStringContainsString('Short Loop', $group->text());
        self::assertStringNotContainsString('Long ', $group->text());
    }

    public function testLastYearsTopThreeCarryTheHandicapMark(): void
    {
        $rid = $this->region('xa-ranked', 'Ranked Hills');
        $a = (int) $this->climb($rid, 'Col Again')->getId();
        $this->db->insert('season_result', [
            'region_id' => $rid, 'category' => 'climbs', 'bike_type' => '', 'season' => 'spring', 'round_start' => '2026-03-01',
            'subject_id' => $a, 'subject_name' => 'Col Again', 'votes' => 6, 'score' => 24, 'handicapped' => 'false',
            'place' => 1, 'wins_before' => 0, 'list_position' => 1, 'voters' => 6, 'frozen_at' => '2026-06-01 02:00:00',
        ]);
        $this->voters($rid, 5, [$a]);

        $crawler = $this->client->request('GET', '/best?cc=XA');

        $group = $crawler->filter('.rgroup')->reduce(static fn ($n): bool => str_contains($n->text(), 'Ranked Hills'));
        self::assertStringContainsString('x0.75', $group->text());
    }

    /**
     * The bar measures each row against the first row's score: B is listed
     * first in a shared place and reads full, although handicapped A has
     * more raw votes (route-domain.md §8c, the worked example).
     */
    public function testTheFirstRowReadsAFullBar(): void
    {
        $rid = $this->region('xa-ranked', 'Ranked Hills');
        $a = (int) $this->climb($rid, 'Col Again')->getId();
        $b = (int) $this->climb($rid, 'Col New')->getId();
        $this->db->insert('season_result', [
            'region_id' => $rid, 'category' => 'climbs', 'bike_type' => '', 'season' => 'spring', 'round_start' => '2026-03-01',
            'subject_id' => $a, 'subject_name' => 'Col Again', 'votes' => 6, 'score' => 24, 'handicapped' => 'false',
            'place' => 1, 'wins_before' => 0, 'list_position' => 1, 'voters' => 6, 'frozen_at' => '2026-06-01 02:00:00',
        ]);
        // Again 65 points (2 x 15 + 2 x 10 + 15), x0.75 = 48.75; New 50 (2 x 10 + 2 x 15).
        $this->voters($rid, 2, [$a, $b]);
        $this->voters($rid, 2, [$b, $a]);
        $this->voters($rid, 1, [$a]);

        $crawler = $this->client->request('GET', '/best?cc=XA');

        $group = $crawler->filter('.rgroup')->reduce(static fn ($n): bool => str_contains($n->text(), 'Ranked Hills'));
        self::assertSame(['Col New', 'Col Again'], $group->filter('ol.rank .nm a')->each(static fn ($n): string => trim($n->text())));
        self::assertSame(['width:100%', 'width:98%'], $group->filter('ol.rank .bar i')->each(static fn ($n): string => (string) $n->attr('style')));
    }

    /** A route list narrowed to one bike counts only the votes cast on that bike. */
    public function testOneBikeShowsOnlyTheListsVotedOnThatBike(): void
    {
        $gravel = $this->region('xa-gravel', 'Gravel Hills');
        $road = $this->region('xa-road', 'Road Hills', 52.0);
        $this->voters($gravel, 5, [$this->route($gravel, 'Dust Loop', 40_000)], 'quality-rides', 'Gravel');
        $this->voters($road, 5, [$this->route($road, 'Tarmac Loop', 40_000)], 'quality-rides', 'Road');

        $crawler = $this->client->request('GET', '/best?cat=quality-rides&bike=Gravel');

        self::assertResponseIsSuccessful();
        self::assertContains('Gravel Hills', self::headings($crawler));
        self::assertNotContains('Road Hills', self::headings($crawler));
    }

    /** route-domain.md §8c: votes for a season still open to voting never reach the page. */
    public function testVotesOnAnOpenBallotStayOffThePage(): void
    {
        $rid = $this->region('xa-next', 'Next Hills');
        $a = (int) $this->climb($rid, 'Col Next')->getId();
        // In April riders vote for summer 2027; last summer had no votes.
        $this->voters($rid, 6, [$a], 'climbs', null, 'summer', '2027-06-01');

        $crawler = $this->client->request('GET', '/best?season=summer&cc=XA');

        $group = $crawler->filter('.rgroup')->reduce(static fn ($n): bool => str_contains($n->text(), 'Next Hills'));
        self::assertStringContainsString('Seasonal rounds 2026', $crawler->filter('.kicker')->first()->text(), 'last summer, the one that has started');
        self::assertSame('No ranking yet', trim($group->filter('h2 .nr-chip')->text()), 'beside the region name (owner 2026-10-03)');
        self::assertCount(0, $group->filter('ol.pod3'));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM season_result WHERE round_start = '2027-06-01'"));
    }

    /** The page says what the Commons keeps: each rider's votes, seen by that rider only (route-domain.md §8d, privacy). */
    public function testThePageSaysWhoSeesAVote(): void
    {
        $this->client->request('GET', '/best');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('A list is published when voting for its season closes.', $html, 'removed (owner 2026-10-03)');
        self::assertStringNotContainsString('Every season starts empty.', $html, 'not true: the handicap carries last year over (owner 2026-10-03)');
        self::assertStringNotContainsString('never what they ranked', $html);
        self::assertStringNotContainsString('re-ranked', $html);
    }

    /** Owner 2026-10-03: no "Now" chip; the season riders are in is lit, and its chip is the bare URL. */
    public function testTheCurrentSeasonIsTheDefaultChip(): void
    {
        $this->region('xa-ranked', 'Ranked Hills');

        $crawler = $this->client->request('GET', '/best?cc=XA');

        $lit = $crawler->filter('.frow a.on')->each(static fn ($n): string => trim($n->text()));
        self::assertContains('Spring', $lit);
        self::assertNotContains('Now', $crawler->filter('.frow a')->each(static fn ($n): string => trim($n->text())));
        $spring = $crawler->filter('.frow a')->reduce(static fn ($n): bool => 'Spring' === trim($n->text()));
        self::assertStringNotContainsString('season=', (string) $spring->attr('href'));
        $summer = $crawler->filter('.frow a')->reduce(static fn ($n): bool => 'Summer' === trim($n->text()));
        self::assertStringContainsString('season=summer', (string) $summer->attr('href'));
    }

    public function testWhileVotingIsOffThePreviewStays(): void
    {
        static::getContainer()->get(SystemSettingsWriter::class)->set(SettingsRegistry::COMMUNITY_VOTING_LIVE, 0, null);

        $this->client->request('GET', '/best');

        self::assertSelectorExists('.prev');
    }

    /** Owner 2026-10-03: the title names the season shown, and beside the filters the open ballot is a button. */
    public function testThePageSaysWhichSeasonItShows(): void
    {
        $this->climb($this->region('xa-season', 'Season Hills'), 'Col Season');

        $crawler = $this->client->request('GET', '/best?cc=XA');

        self::assertSame('The best of Spring', trim($crawler->filter('h1')->text()));
        self::assertSame('Spring', $crawler->filter('h1 em')->text());
        self::assertSame('Seasonal rounds 2027', trim($crawler->filter('.kicker')->first()->text()));
        $line = $crawler->filter('.season-now')->text();
        self::assertStringContainsString('Voting for Summer 2027 is open until', $line);
        self::assertSame('Vote for next season', trim($crawler->filter('.season-now a.vote-cta')->text()));
        $winter = $this->client->request('GET', '/best?season=winter&cc=XA');
        self::assertSame('The best of Winter', trim($winter->filter('h1')->text()));
        self::assertSame('Seasonal rounds 2026-27', trim($winter->filter('.kicker')->first()->text()));
        $everywhere = $this->client->request('GET', '/best');
        self::assertSame('Everywhere', trim($everywhere->filter('.hscope')->text()));
        self::assertCount(0, $everywhere->filter('.hscope img'), 'no flag for Everywhere');
        self::assertCount(0, $crawler->filter('.hscope img'), 'XA has no flag file');
        $this->db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES ('be-flag', 'Flag Vale', ST_GeomFromText('POLYGON((5 50,5 51,6 51,6 50,5 50))', 4326), 1000, 'BE', 'BEFLAG', 4, 'test', NOW(), NOW())",
        );
        self::assertCount(0, $crawler->filter('.hscope .hout'), 'no outline without a country row');
        // The country row (admin level 2) carries the outline the title draws.
        $this->db->executeStatement(
            "INSERT INTO region (slug, name, geom, outline, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES ('be-country', 'Belgium', ST_GeomFromText('POLYGON((4 50,4 51,6 51,6 50,4 50))', 4326), '[[4,50,6,50,6,51,4,51,4,50]]', 30000, 'BE', 'BE', 2, 'test', NOW(), NOW())",
        );
        $belgium = $this->client->request('GET', '/best?cc=BE');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $belgium->filter('.hscope img[src*="flags/be"]'));
        self::assertCount(1, $belgium->filter('.hscope svg.hout path'));
        self::assertStringNotContainsString('South of the equator', $line, 'a country has one hemisphere');

        $everywhere = $this->client->request('GET', '/best')->filter('.season-now')->text();
        self::assertStringContainsString('South of the equator', $everywhere);
    }

    /** Owner 2026-10-03: a signed-in rider lands on their own country, their home region first. */
    public function testASignedInRiderOpensOnTheirHomeRegion(): void
    {
        $other = $this->region('xa-alpha', 'Alpha Vale');
        $home = $this->region('xa-home', 'Zulu Home', 52.0);
        $this->climb($other, 'Col Alpha');
        $this->climb($home, 'Col Home');
        $rider = (new User())->setEmail('best-home-'.bin2hex(random_bytes(3)).'@test.test')->setDisplayName('Homer');
        $rider->setPassword('x');
        $rider->setBaseRegionIds([$home])->setBaseCountryCodes(['XA']);
        $this->em->persist($rider);
        $this->em->flush();
        $this->client->loginUser($rider);

        $this->client->request('GET', '/best');

        self::assertResponseRedirects('/best?cc=XA#r-xa-home');
        $crawler = $this->client->request('GET', '/best?cc=XA');
        $first = $crawler->filter('.rgroup')->first();
        self::assertSame('r-xa-home', $first->attr('id'));
        self::assertStringContainsString('Your region', $first->filter('h2')->text());
        self::assertCount(1, $crawler->filter('.rgroup.home'));
    }

    public function testAnAnonymousVisitorKeepsTheBarePage(): void
    {
        $this->client->request('GET', '/best');

        self::assertResponseIsSuccessful();
    }

    /** Owner 2026-10-03: beside the filters, how long the ballot stays open, counting down. */
    public function testTheOpenBallotCountsDown(): void
    {
        $crawler = $this->client->request('GET', '/best');

        // 10 April 2027, 12:00 UTC; summer's ballot closes on 1 June: 51 days.
        $count = $crawler->filter('.season-now [data-countdown]');
        self::assertSame('51 days left', trim($count->text()));
        self::assertSame('2027-06-01T00:00:00+00:00', $count->attr('data-deadline'));
        self::assertSame('%h% h %m% min left', $count->attr('data-t-hm'));
    }

    /** Owner 2026-10-03: a southern round carries a globe with its southern half filled. */
    public function testASouthernCountryShowsTheSouthernHalfOfTheGlobe(): void
    {
        $this->db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES ('nz-south', 'South Vale', ST_GeomFromText('POLYGON((170 -45,170 -44,171 -44,171 -45,170 -45))', 4326), 1000, 'NZ', 'NZSOUTH', 4, 'test', NOW(), NOW())",
        );

        $crawler = $this->client->request('GET', '/best?cc=NZ');

        self::assertResponseIsSuccessful();
        self::assertSame('South of the equator', $crawler->filter('.season-now .vopen svg.hemi')->attr('aria-label'));
        self::assertStringContainsString('Voting for Winter 2027', $crawler->filter('.season-now .vopen')->text(), 'April is southern autumn: the open ballot is for the southern winter');
    }
}
