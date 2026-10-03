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

final class BallotPageTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        Clock::set(new MockClock(new \DateTimeImmutable('2027-04-10T12:00:00+00:00')));
        $this->client = static::createClient();
        $this->db = static::getContainer()->get(Connection::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    private function openVoting(): void
    {
        static::getContainer()->get(SystemSettingsWriter::class)->set(SettingsRegistry::COMMUNITY_VOTING_LIVE, 1, null);
    }

    private function region(string $slug = 'xa-ballot'): int
    {
        $this->db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES (?, 'Ballot Hills', ST_GeomFromText('POLYGON((5 50,5 51,6 51,6 50,5 50))', 4326), 1000, 'XA', ?, 4, 'test', NOW(), NOW())",
            [$slug, strtoupper(substr($slug, 0, 8))],
        );

        return (int) $this->db->fetchOne('SELECT id FROM region WHERE slug = ?', [$slug]);
    }

    private function item(int $regionId, string $name, string $letter = 'N'): int
    {
        $item = (new Item())->setLetter($letter)->setName($name)
            ->setGeom('{"type":"Point","coordinates":[5.5,50.5]}')->setCountryCode('XA')
            ->setState(ItemState::Verified)->setSource(ItemSource::Osm)->setSourceRef('node/'.bin2hex(random_bytes(4)))
            ->setAttributes([])->setRegionId($regionId);
        $this->em->persist($item);
        $this->em->flush();

        return (int) $item->getId();
    }

    /** @param list<string> $bikes the bike types the route declares */
    private function route(int $regionId, string $name, array $bikes = []): int
    {
        $route = (new RecommendedRoute())->setName($name)
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(20000)->setState(ItemState::Verified)->setSource(ItemSource::User)
            ->setSourceRef('user:page-'.bin2hex(random_bytes(6)))->setRegionId($regionId);
        if ([] !== $bikes) {
            $route->setAttributes(['bikeTypes' => $bikes]);
        }
        $this->em->persist($route);
        $this->em->flush();

        return (int) $route->getId();
    }

    private function rider(string $createdAt = '2027-03-01 09:00:00', bool $active = true): User
    {
        $u = (new User())->setEmail('page-'.bin2hex(random_bytes(4)).'@test.test')->setDisplayName('Voter');
        $u->setEmailVerified(true)->setRoles([])->setPassword('not-a-real-hash');
        $this->em->persist($u);
        $this->em->flush();
        $this->db->executeStatement('UPDATE users SET created_at = ? WHERE id = ?', [$createdAt, $u->getId()]);
        if ($active) {
            $this->db->executeStatement("INSERT INTO route_ride (route_id, user_id, bike_type, created_at) VALUES (1, ?, 'Road', NOW())", [$u->getId()]);
        }
        $this->em->refresh($u);
        $this->client->loginUser($u);

        return $u;
    }

    public function testAnonymousRidersAreSentToSignIn(): void
    {
        $this->client->request('GET', '/vote');
        self::assertResponseRedirects('/login?_target_path=%2Fvote', 302);
    }

    public function testWhileVotingIsOffTheListShowsAndNothingCanBeCast(): void
    {
        $rid = $this->region();
        $this->item($rid, 'Mur de Ballot');
        $this->rider();

        $crawler = $this->client->request('GET', '/vote?region=xa-ballot');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.vclosed', 'The season ballot is not open yet.');
        // The tabs open the main content, right under the header; the region picker is in the header.
        self::assertSelectorExists('#main > nav.vtabs:first-child');
        self::assertSelectorExists('.vhead form.vregion select#v-region');
        self::assertCount(1, $crawler->filter('nav.vtabs ~ .vclosed'));
        self::assertSelectorTextContains('.vgrid #cands .cand', 'Mur de Ballot');
        self::assertSelectorTextContains('.vgrid .ballot .bempty', 'Pick 5 from the list.');
        self::assertCount(0, $crawler->filter('button[name="do"]'));
    }

    public function testARiderVotesFromTheListAndCanTakeItBack(): void
    {
        $this->openVoting();
        $rid = $this->region();
        $climb = $this->item($rid, 'Mur de Ballot');
        $u = $this->rider();

        $crawler = $this->client->request('GET', '/vote?region=xa-ballot&cat=climbs');
        // April is spring, so the ballot open now fills the summer list.
        self::assertSelectorTextContains('.round', 'Voting for Summer 2027');
        // 10 April 2027, 12:00 UTC; the summer ballot closes on 1 June.
        self::assertSelectorTextSame('.vhead .vcount', '51 days left');
        self::assertSame('North of the equator', $crawler->filter('.round svg.hemi')->attr('aria-label'), 'a globe with its half of the world filled');
        $this->client->submit($crawler->filter('#c-'.$climb.' button[value="cast"]')->form());

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/vote?region=xa-ballot&cat=climbs');
        $crawler = $this->client->followRedirect();
        self::assertSelectorNotExists('.flash-success', 'no "your vote is in" line: the ballot shows it');
        self::assertSelectorExists('.ballot .bitem.bnew', 'the vote just cast fades in');
        self::assertSelectorTextContains('#c-'.$climb.' .add.in', 'on ballot');
        self::assertSelectorTextContains('#c-'.$climb.' button.add.in .vh', 'remove Mur de Ballot');
        self::assertSelectorTextContains('.ballot h3', 'Your ballot · Climbs');
        self::assertSelectorTextContains('.ballot .bitem', 'Mur de Ballot');
        self::assertSelectorTextContains('.ballot .bitem .brank', '1');
        self::assertSelectorTextContains('.ballot .bitem .bpts', '15 points');
        self::assertSelectorTextContains('.ballot .vleft', 'You have 4 votes left in this list.');
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM season_vote WHERE user_id = ? AND subject_id = ?', [$u->getId(), $climb]));

        $remove = $crawler->filter('.ballot .bitem button[value="remove"]')->form();
        $this->client->submit($remove);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Your vote is removed.');
        self::assertSelectorExists('.ballot .bempty');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM season_vote WHERE user_id = ?', [$u->getId()]));

        // The same form again: nothing left to remove, so no claim that something was.
        $this->client->submit($remove);
        $this->client->followRedirect();
        self::assertSelectorNotExists('.flash-success');
    }

    public function testAPickFromTheMapOpensItsRegionAndCategory(): void
    {
        $this->openVoting();
        $rid = $this->region();
        $view = $this->item($rid, 'Lookout Point', 'P');
        $this->rider();

        $this->client->request('GET', '/vote?cat=scenic-views&pick='.$view);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#c-'.$view.'.picked');
        self::assertSelectorExists('option[value="xa-ballot"][selected]');
        self::assertSelectorTextContains('.vtabs a.on', 'Scenic views');
    }

    public function testARiderWhoCannotVoteYetIsToldWhy(): void
    {
        $this->openVoting();
        $rid = $this->region();
        $this->item($rid, 'Mur de Ballot');
        $this->rider('2027-04-05 09:00:00', false);

        $crawler = $this->client->request('GET', '/vote?region=xa-ballot');

        self::assertSelectorTextContains('.vneeds', 'You cannot vote yet');
        self::assertSelectorTextContains('.vneeds', 'Your account can vote from');
        self::assertSelectorTextContains('.vneeds', 'Do one thing on the map first');
        // A pending contribution counts (route-domain.md §8d): it need not be accepted yet.
        self::assertSelectorTextContains('.vneeds', 'or send a contribution.');
        self::assertCount(0, $crawler->filter('button[name="do"]'));
    }

    public function testARouteVoteTakesTheBike(): void
    {
        $this->openVoting();
        $rid = $this->region();
        $route = $this->route($rid, 'Ballot Loop');
        $u = $this->rider();

        $crawler = $this->client->request('GET', '/vote?region=xa-ballot&cat=quality-rides');
        $form = $crawler->filter('#c-'.$route.' button[value="cast"]')->form();
        $form['bike']->select('Gravel');
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('Gravel', $this->db->fetchOne('SELECT bike_type FROM season_vote WHERE user_id = ?', [$u->getId()]));
    }

    /** The general bikes for every route; a specialty bike only where the route declares it (route-domain.md §8.3). */
    public function testTheBikeChoiceOffersTheSpecialtyBikesTheRouteDeclares(): void
    {
        $this->openVoting();
        $rid = $this->region();
        $plain = $this->route($rid, 'Plain Loop');
        $tandem = $this->route($rid, 'Tandem Loop', ['Road', 'Tandem']);
        $this->rider();

        $crawler = $this->client->request('GET', '/vote?region=xa-ballot&cat=quality-rides');
        $offered = static fn (int $id): array => $crawler->filter('#b-'.$id.' option')->each(static fn ($n): string => (string) $n->attr('value'));

        self::assertSame(['', 'Road', 'Gravel', 'MTB', 'E-bike'], $offered($plain));
        self::assertSame(['', 'Road', 'Gravel', 'MTB', 'E-bike', 'Tandem'], $offered($tandem));
    }

    /** route-domain.md §8c: the ballot shows the rider's own votes and no count of anyone else's. */
    public function testTheBallotShowsNoCountOfOtherRiders(): void
    {
        $this->openVoting();
        $rid = $this->region();
        $climb = $this->item($rid, 'Counted');
        foreach ([9101, 9102] as $other) {
            $this->db->insert('season_vote', [
                'user_id' => $other, 'region_id' => $rid, 'category' => 'climbs', 'subject_id' => $climb,
                'bike_type' => null, 'season' => 'summer', 'round_start' => '2027-06-01', 'slot' => 1, 'created_at' => '2027-04-02 10:00:00',
            ]);
        }
        $this->rider();

        $crawler = $this->client->request('GET', '/vote?region=xa-ballot');

        $text = $crawler->filter('#main')->text();
        self::assertStringNotContainsString('voters', $text);
        self::assertStringNotContainsString('A list gets a ranking', $text);
        self::assertStringNotContainsString('Your vote is private', $text);
    }

    public function testARiderPutsTheirVotesInOrder(): void
    {
        $this->openVoting();
        $rid = $this->region();
        [$one, $two] = [$this->item($rid, 'Côte Une'), $this->item($rid, 'Côte Deux')];
        $this->rider();
        foreach ([$one, $two] as $id) {
            $crawler = $this->client->request('GET', '/vote?region=xa-ballot');
            $this->client->submit($crawler->filter('#c-'.$id.' button[value="cast"]')->form());
        }

        $crawler = $this->client->request('GET', '/vote?region=xa-ballot');
        self::assertSame(['Côte Une 15 points', 'Côte Deux 10 points'], $crawler->filter('.ballot .bitem .bname')->each(static fn ($n): string => $n->text()));
        self::assertNotNull($crawler->filter('.ballot .bitem')->first()->filter('button[value="up"]')->attr('hidden'), 'the first choice has nowhere to go up');
        self::assertNotNull($crawler->filter('.ballot .bitem')->last()->filter('button[value="down"]')->attr('hidden'), 'the last choice has nowhere to go down');
        self::assertSame('[15,10,7,4,2]', $crawler->filter('#ballot')->attr('data-points'));

        $this->client->submit($crawler->filter('.ballot .bitem')->last()->filter('button[value="up"]')->form());
        self::assertResponseStatusCodeSame(303);
        $crawler = $this->client->followRedirect();

        self::assertStringStartsWith('Côte Deux', $crawler->filter('.ballot .bitem .bname')->first()->text());
        self::assertSame(['15 points', '10 points'], $crawler->filter('.ballot .bitem .bpts')->each(static fn ($n): string => $n->text()));
    }

    public function testAFullListOffersNoMoreVotes(): void
    {
        $this->openVoting();
        $rid = $this->region();
        $ids = [];
        foreach (['One', 'Two', 'Three', 'Four', 'Five', 'Six'] as $name) {
            $ids[] = $this->item($rid, $name);
        }
        $this->rider();

        foreach (\array_slice($ids, 0, 5) as $id) {
            $crawler = $this->client->request('GET', '/vote?region=xa-ballot');
            $this->client->submit($crawler->filter('#c-'.$id.' button[value="cast"]')->form());
        }
        $crawler = $this->client->request('GET', '/vote?region=xa-ballot');

        self::assertSelectorTextContains('.ballot .vleft', 'You have used your 5 votes in this list.');
        self::assertCount(5, $crawler->filter('.ballot .bitem'));
        self::assertCount(0, $crawler->filter('#c-'.$ids[5].' button[value="cast"]'));
    }

    /** The page's arrows are a background call (ballot.js): JSON back, no redirect, no flash. */
    public function testAMoveAskedForAsJsonAnswersWithoutAReload(): void
    {
        $this->openVoting();
        $rid = $this->region();
        [$one, $two] = [$this->item($rid, 'Col Un'), $this->item($rid, 'Col Deux')];
        $u = $this->rider();
        foreach ([$one, $two] as $id) {
            $crawler = $this->client->request('GET', '/vote?region=xa-ballot');
            $this->client->submit($crawler->filter('#c-'.$id.' button[value="cast"]')->form());
        }
        $crawler = $this->client->request('GET', '/vote?region=xa-ballot');
        $form = $crawler->filter('.ballot .bitem')->last()->filter('button[value="up"]')->form();

        $this->client->request('POST', $form->getUri(), $form->getValues() + ['do' => 'up'], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        self::assertSame(['moved' => true], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT slot FROM season_vote WHERE user_id = ? AND subject_id = ?', [$u->getId(), $two]));
    }

    public function testMyRouteVoteNamesTheBikeInTheReadersLanguage(): void
    {
        $this->openVoting();
        $rid = $this->region();
        $route = $this->route($rid, 'Ballot Loop');
        $u = $this->rider();
        $this->db->executeStatement(
            "INSERT INTO season_vote (user_id, region_id, category, subject_id, bike_type, season, round_start, slot, created_at)
             VALUES (?, ?, 'quality-rides', ?, 'E-bike', 'summer', '2027-06-01', 1, NOW())",
            [$u->getId(), $rid, $route],
        );

        $this->client->request('GET', '/fr/voter?region=xa-ballot&cat=quality-rides');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.ballot .bitem', 'Ballot Loop · VAE');
        self::assertSelectorTextNotContains('.ballot .bitem', 'E-bike');
        self::assertSelectorExists('#c-'.$route.' .add.in');
    }

    public function testUnknownCategoriesAndPicksAreIgnored(): void
    {
        $this->openVoting();
        $rid = $this->region();
        $climb = $this->item($rid, 'Mur de Ballot');
        $view = $this->item($rid, 'Lookout Point', 'P');
        $this->rider();

        $this->client->request('GET', '/vote?region=xa-ballot&cat=water-food&pick=not-a-number');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.vtabs a.on', 'Climbs');
        self::assertSelectorExists('#c-'.$climb);
        self::assertSelectorNotExists('.picked');

        // A pick of another kind than the list is not pulled into it.
        $this->client->request('GET', '/vote?cat=climbs&pick='.$view);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#c-'.$view);
        self::assertSelectorNotExists('option[selected][value="xa-ballot"]');
    }

    public function testAVoteForAKindThatIsNotOnTheBallotFallsBackToClimbsAndIsRefused(): void
    {
        $this->openVoting();
        $rid = $this->region();
        $view = $this->item($rid, 'Lookout Point', 'P');
        $this->rider();
        $crawler = $this->client->request('GET', '/vote?region=xa-ballot&cat=scenic-views');
        $form = $crawler->filter('#c-'.$view.' button[value="cast"]')->form();
        $form['cat']->setValue('water-food');
        $this->client->submit($form);

        self::assertResponseRedirects('/vote?region=xa-ballot&cat=climbs', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'That is not on this ballot.');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM season_vote'));
    }

    public function testAForgedTokenIsRefused(): void
    {
        $this->openVoting();
        $climb = $this->item($this->region(), 'Mur de Ballot');
        $this->rider();

        $this->client->request('POST', '/vote', ['_token' => 'forged', 'do' => 'cast', 'cat' => 'climbs', 'id' => $climb, 'region' => 'xa-ballot']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM season_vote'));
    }

    public function testARiderSubmitsAFullBallotAndItIsFinal(): void
    {
        $this->openVoting();
        $rid = $this->region();
        $ids = [];
        foreach (['One', 'Two', 'Three', 'Four', 'Five'] as $name) {
            $ids[] = $this->item($rid, $name);
        }
        $this->rider();

        $crawler = $this->client->request('GET', '/vote?region=xa-ballot');
        $this->client->submit($crawler->filter('#c-'.$ids[0].' button[value="cast"]')->form());
        $crawler = $this->client->request('GET', '/vote?region=xa-ballot');
        self::assertNotNull($crawler->filter('.vsubmit button[value="submit"]')->attr('disabled'), 'Submit waits for all 5 votes');
        self::assertSelectorTextContains('.vsubmit-note', 'Pick all 5 to submit.');

        foreach (\array_slice($ids, 1) as $id) {
            $crawler = $this->client->request('GET', '/vote?region=xa-ballot');
            $this->client->submit($crawler->filter('#c-'.$id.' button[value="cast"]')->form());
        }
        $crawler = $this->client->request('GET', '/vote?region=xa-ballot');
        self::assertNull($crawler->filter('.vsubmit button[value="submit"]')->attr('disabled'));
        $this->client->submit($crawler->filter('.vsubmit button[value="submit"]')->form());
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-success', 'Your ballot is submitted.');
        self::assertSelectorTextContains('.vsubmitted', 'Your ballot counts and is final.');
        self::assertCount(1, $crawler->filter('.vtabs a.on .vdone'), 'the climbs tab carries a check');
        self::assertCount(1, $crawler->filter('.vtabs a .vdone'), 'only the submitted category');
        self::assertCount(1, $crawler->filter('.ballot h3 .vdone'));
        self::assertCount(0, $crawler->filter('.ballot button'), 'no move, remove or submit after submitting');
        self::assertCount(0, $crawler->filter('#cands button'), 'no new votes after submitting');
        self::assertCount(5, $crawler->filter('#cands .add.in.mark'));
    }

    /** Owner 2026-10-03: a ballot row says more than a name, in less space: one line from the catalogue, and a picture or the kind's icon. */
    public function testARowCarriesALineAboutThePlaceAndAPictureSlot(): void
    {
        $this->openVoting();
        $rid = $this->region();
        $climb = $this->item($rid, 'Col Facts');
        $this->db->executeStatement(
            'UPDATE item SET attributes = ?::jsonb WHERE id = ?',
            [(string) json_encode(['length' => 2000, 'avgGradient' => '9.0%', 'gain' => 180]), $climb],
        );
        $this->rider();

        $crawler = $this->client->request('GET', '/vote?region=xa-ballot');

        $note = $crawler->filter('#c-'.$climb.' .note')->text();
        self::assertStringContainsString('9.0%', $note);
        self::assertStringContainsString('180 m up', $note);
        self::assertCount(1, $crawler->filter('#c-'.$climb.' .cshot svg'), 'no photo, so the kind\'s icon');
    }

    /** Owner 2026-10-03: A to Z, and a long list can be narrowed by name. */
    public function testTheBallotIsAToZWithAFindBox(): void
    {
        $this->openVoting();
        $rid = $this->region();
        foreach (['Zénith', 'Alpha', 'Mid'] as $name) {
            $this->item($rid, $name);
        }
        $this->rider();

        $crawler = $this->client->request('GET', '/vote?region=xa-ballot');

        self::assertSame(['Alpha', 'Mid', 'Zénith'], $crawler->filter('#cands .cand h4')->each(static fn ($n): string => trim($n->text())));
        self::assertCount(1, $crawler->filter('.vgrid > #cands > [data-cand-find][hidden] input#cand-find'), 'inside the list column, revealed by ballot.js on a long list');
        self::assertCount(2, $crawler->filter('.vgrid > *'), 'the grid keeps its two columns: the list and the ballot');
    }

    /** Owner 2026-10-03: a place to sleep shows its kind, in an icon where there is no picture and in words, and its town. */
    public function testAStayWithoutAPictureShowsItsKind(): void
    {
        $this->openVoting();
        $rid = $this->region();
        $hotel = $this->item($rid, 'Hotel Sans Photo');
        $castle = $this->item($rid, 'Castle Stay');
        $this->db->executeStatement("UPDATE item SET letter = 'O', attributes = '{\"t\":\"Hotel\",\"town\":\"Spa\"}'::jsonb WHERE id = ?", [$hotel]);
        $this->db->executeStatement("UPDATE item SET letter = 'O', attributes = '{\"t\":\"Castle\"}'::jsonb WHERE id = ?", [$castle]);
        $this->rider();

        $crawler = $this->client->request('GET', '/vote?region=xa-ballot&cat=where-to-sleep');

        self::assertSame(\App\Catalog\StayKind::PATHS['hotel'], $crawler->filter('#c-'.$hotel.' .cshot path')->attr('d'));
        self::assertSame(\App\Catalog\StayKind::PATHS['unknown'], $crawler->filter('#c-'.$castle.' .cshot path')->attr('d'), 'an unknown kind is a house with a question mark');
        self::assertSame('Hotel · Spa', trim($crawler->filter('#c-'.$hotel.' .note')->text()), 'the kind in words, and the town');
        self::assertSame('Kind not known', trim($crawler->filter('#c-'.$castle.' .note')->text()));
    }
}
