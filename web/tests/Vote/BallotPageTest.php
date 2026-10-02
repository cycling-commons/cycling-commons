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

    private function route(int $regionId, string $name): int
    {
        $route = (new RecommendedRoute())->setName($name)
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(20000)->setState(ItemState::Verified)->setSource(ItemSource::User)
            ->setSourceRef('user:page-'.bin2hex(random_bytes(6)))->setRegionId($regionId);
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
        self::assertSelectorTextContains('.vgrid #cands .cand', 'Mur de Ballot');
        self::assertSelectorTextContains('.vgrid .ballot .bempty', 'Pick up to 3 from the list.');
        self::assertCount(0, $crawler->filter('button[name="do"]'));
    }

    public function testARiderVotesFromTheListAndCanTakeItBack(): void
    {
        $this->openVoting();
        $rid = $this->region();
        $climb = $this->item($rid, 'Mur de Ballot');
        $u = $this->rider();

        $crawler = $this->client->request('GET', '/vote?region=xa-ballot&cat=climbs');
        self::assertSelectorTextContains('.round', 'Spring 2027');
        $this->client->submit($crawler->filter('#c-'.$climb.' button[value="cast"]')->form());

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/vote?region=xa-ballot&cat=climbs');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Your vote is in.');
        self::assertSelectorTextContains('#c-'.$climb.' .add.in', 'on ballot');
        self::assertSelectorTextContains('.ballot h3', 'Your ballot · Climbs');
        self::assertSelectorTextContains('.ballot .bitem', 'Mur de Ballot');
        self::assertSelectorTextContains('.ballot .vleft', 'You have 2 votes left in this list.');
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

    public function testAFullListOffersNoMoreVotes(): void
    {
        $this->openVoting();
        $rid = $this->region();
        $ids = [$this->item($rid, 'One'), $this->item($rid, 'Two'), $this->item($rid, 'Three'), $this->item($rid, 'Four')];
        $this->rider();

        foreach (\array_slice($ids, 0, 3) as $id) {
            $crawler = $this->client->request('GET', '/vote?region=xa-ballot');
            $this->client->submit($crawler->filter('#c-'.$id.' button[value="cast"]')->form());
        }
        $crawler = $this->client->request('GET', '/vote?region=xa-ballot');

        self::assertSelectorTextContains('.ballot .vleft', 'You have used your 3 votes in this list.');
        self::assertCount(3, $crawler->filter('.ballot .bitem'));
        self::assertCount(0, $crawler->filter('#c-'.$ids[3].' button[value="cast"]'));
    }

    public function testMyRouteVoteNamesTheBikeInTheReadersLanguage(): void
    {
        $this->openVoting();
        $rid = $this->region();
        $route = $this->route($rid, 'Ballot Loop');
        $u = $this->rider();
        $this->db->executeStatement(
            "INSERT INTO season_vote (user_id, region_id, category, subject_id, bike_type, season, round_start, slot, created_at)
             VALUES (?, ?, 'quality-rides', ?, 'E-bike', 'spring', '2027-03-01', 1, NOW())",
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
}
