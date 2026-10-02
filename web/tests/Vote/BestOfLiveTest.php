<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Vote;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
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
    private function voters(int $regionId, int $count, array $subjects, string $category = 'climbs', ?string $bike = null): void
    {
        for ($v = 0; $v < $count; ++$v) {
            $user = $this->user++;
            foreach ($subjects as $slot => $subject) {
                $this->db->insert('season_vote', [
                    'user_id' => $user, 'region_id' => $regionId, 'category' => $category, 'subject_id' => $subject,
                    'bike_type' => $bike, 'season' => 'spring', 'round_start' => '2027-03-01', 'slot' => $slot + 1,
                    'created_at' => '2027-04-01 10:00:00',
                ]);
            }
        }
    }

    public function testARankedListShowsItsPlacesAndASharedFirst(): void
    {
        $rid = $this->region('xa-ranked', 'Ranked Hills');
        $a = (int) $this->climb($rid, 'Col A')->getId();
        $b = (int) $this->climb($rid, 'Col B')->getId();
        $c = (int) $this->climb($rid, 'Col C')->getId();
        $this->voters($rid, 5, [$a, $b]);
        $this->voters($rid, 1, [$c]);

        $crawler = $this->client->request('GET', '/best?cc=XA');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.prev');
        $group = $crawler->filter('.rgroup')->reduce(static fn ($n): bool => str_contains($n->text(), 'Ranked Hills'));
        self::assertCount(1, $group);
        self::assertStringContainsString('Spring 2027', $group->text());
        self::assertStringContainsString('6 voters', $group->text());
        self::assertSame(['1=', '1=', '3'], $group->filter('ol.rank .n')->each(static fn ($n): string => trim($n->text())));
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
        self::assertStringContainsString('2 of 5 voters', $group->text());
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

    public function testNowIsTheDefaultSeason(): void
    {
        $this->region('xa-ranked', 'Ranked Hills');

        $crawler = $this->client->request('GET', '/best?cc=XA');

        self::assertContains('Now', $crawler->filter('.frow a.on')->each(static fn ($n): string => trim($n->text())));
    }

    public function testWhileVotingIsOffThePreviewStays(): void
    {
        static::getContainer()->get(SystemSettingsWriter::class)->set(SettingsRegistry::COMMUNITY_VOTING_LIVE, 0, null);

        $this->client->request('GET', '/best');

        self::assertSelectorExists('.prev');
    }
}
