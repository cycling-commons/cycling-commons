<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Traffic;

use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use App\Traffic\TrafficStore;
use App\Traffic\TrafficView;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Declared beside measured (docs/specs/traffic-measurements.md §4.6): the
 * first deliverable, a table for curators to judge whether the numbers tell
 * the truth.
 */
final class ModerateTrafficControllerTest extends WebTestCase
{
    private const int WAY = 4521877;

    private ?int $region = null;

    private function curator(KernelBrowser $client, bool $twoFactor = true, array $roles = ['ROLE_CURATOR']): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u = (new User())->setEmail('cmp-'.bin2hex(random_bytes(3)).'@example.com');
        $u->setPassword('x');
        $u->setDisplayName('Curator');
        $u->setEmailVerified(true);
        $u->setRoles($roles);
        if ($twoFactor) {
            $u->setTotpSecret('JBSWY3DPEHPK3PXP');
            $u->setTwoFaEnabled(true);
        }
        $em->persist($u);
        $em->flush();
        $client->loginUser($u);
    }

    private function road(string $name, string $declared, int $way = self::WAY): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = (new Item())->setLetter('A')->setName($name)
            ->setGeom('{"type":"LineString","coordinates":[[5.0,52.0],[5.0,52.01]]}')
            ->setCountryCode('NL')->setSource(ItemSource::User)->setState(ItemState::Verified)
            ->setSourceRef('way/'.$way)->setAttributes(['traffic' => $declared]);
        $em->persist($item);
        $em->flush();
    }

    private function measured(int $riders, int $way = self::WAY, string $label = 'r'): void
    {
        $store = static::getContainer()->get(TrafficStore::class);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        for ($r = 1; $r <= $riders; ++$r) {
            $line = [
                'way' => $way, 'region' => $this->region, 'dir' => 'f', 'label' => $label, 'slot' => 30, 'dayType' => 'workday', 'season' => 'autumn',
                'quarter' => $now->format('Y').'-Q'.(intdiv((int) $now->format('n') - 1, 3) + 1),
                'day' => intdiv($now->getTimestamp(), 86400) - $r, 'distanceM' => 1000, 'timeS' => 150,
                'passes' => 'p' === $label ? 0 : 4, 'nearby' => 'p' === $label ? 4 : 0,
                'avgSpeedKmh' => 24.0, 'carSpeedBins' => null,
            ];
            $store->addToCell($line);
            $store->addToRider(2000 + $r, $line);
        }
        static::getContainer()->get(TrafficView::class)->invalidate();
    }

    public function testADeclaredRoadIsListedBesideItsMeasuredTraffic(): void
    {
        $client = static::createClient();
        $this->road('Dorpsweg', 'Busy');
        $this->measured(5);
        $this->curator($client);

        $page = $client->request('GET', '/moderate/traffic');

        self::assertResponseIsSuccessful();
        $row = $page->filter('tr[data-way="'.self::WAY.'"]');
        self::assertCount(1, $row);
        self::assertStringContainsString('Dorpsweg', $row->text());
        self::assertStringContainsString('Busy', $row->text());
        self::assertStringContainsString('4', $row->filter('[data-group="workday"]')->text());
    }

    public function testACyclePathShowsItsNearbyCarsMarkedAsNearby(): void
    {
        // A cycle path declared beside a busy road: no car passed its riders.
        $client = static::createClient();
        $this->road('Fietspad Dorpsweg', 'Busy');
        $this->measured(5, self::WAY, 'p');
        $this->curator($client);

        $page = $client->request('GET', '/moderate/traffic');

        self::assertResponseIsSuccessful();
        $cell = $page->filter('tr[data-way="'.self::WAY.'"] [data-group="workday"]');
        self::assertStringContainsString('4.0', $cell->text());
        self::assertStringContainsString('nearby', $cell->text());
    }

    public function testTheDeskMenuLinksHereAndMarksItCurrent(): void
    {
        $client = static::createClient();
        $this->curator($client);

        $page = $client->request('GET', '/moderate/traffic');

        self::assertResponseIsSuccessful();
        $link = $page->filter('#cc-mod-more a[href$="/moderate/traffic"]');
        self::assertCount(1, $link, 'Measured traffic sits under More in the desk menu');
        self::assertStringContainsString('on', (string) $link->attr('class'));
    }

    /** A region the road-piece tiles name; the lines of measured() carry it. */
    private function placed(string $region): void
    {
        $db = static::getContainer()->get(\Doctrine\DBAL\Connection::class);
        $this->region = (int) $db->fetchOne(
            "INSERT INTO region (slug, name, country_code, geom, area_km2, created_at, updated_at)
             VALUES (:slug, :name, 'ZZ', ST_GeomFromText('POLYGON((0 0,1 0,1 1,0 1,0 0))', 4326), 1, now(), now()) RETURNING id",
            ['slug' => 'test-'.bin2hex(random_bytes(4)), 'name' => $region],
        );
    }

    public function testFromTheFirstRideARegionSaysItIsBuildingData(): void
    {
        // One rider: the road's numbers stay hidden, its region counts it.
        $client = static::createClient();
        $this->placed('Zuiderland');
        $this->measured(1);
        $this->curator($client);

        $page = $client->request('GET', '/moderate/traffic');

        self::assertResponseIsSuccessful();
        $row = $page->filter('[data-progress-region="Zuiderland"]');
        self::assertCount(1, $row);
        self::assertSame('1', trim($row->filter('[data-building]')->text()));
        self::assertSame('0', trim($row->filter('[data-usable]')->text()));
        self::assertCount(0, $page->filter('tr[data-way="'.self::WAY.'"]'), 'no road numbers below the rules');
    }

    public function testAUsableRoadCountsAsUsableInItsRegion(): void
    {
        $client = static::createClient();
        $this->placed('Zuiderland');
        $this->measured(5);
        $this->curator($client);

        $page = $client->request('GET', '/moderate/traffic');

        $row = $page->filter('[data-progress-region="Zuiderland"]');
        self::assertSame('0', trim($row->filter('[data-building]')->text()));
        self::assertSame('1', trim($row->filter('[data-usable]')->text()));
    }

    public function testThePageSaysWhenTheNumbersWereLastBuilt(): void
    {
        $client = static::createClient();
        $this->curator($client);

        $page = $client->request('GET', '/moderate/traffic');

        self::assertResponseIsSuccessful();
        $built = $page->filter('[data-built-at]');
        self::assertCount(1, $built);
        self::assertMatchesRegularExpression('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}/', (string) $built->attr('data-built-at'));
        self::assertNotSame('', trim($built->text()));
    }

    public function testEachCountryFoldsOpenWithItsFlagAndTotals(): void
    {
        $client = static::createClient();
        $this->placed('Zuiderland');
        $this->measured(1);
        $this->curator($client);

        $page = $client->request('GET', '/moderate/traffic');

        $country = $page->filter('details[data-progress-country="ZZ"]');
        self::assertCount(1, $country);
        self::assertNotNull($country->attr('open'), 'a country with data starts open');
        $summary = $country->filter('summary');
        self::assertSame('1', trim($summary->filter('[data-building]')->text()));
        self::assertCount(1, $country->filter('[data-progress-region="Zuiderland"]'), 'its regions inside the fold');
    }

    public function testARoadWithTooFewRidersIsNotListed(): void
    {
        $client = static::createClient();
        $this->road('Achterweg', 'Quiet');
        $this->measured(4);
        $this->curator($client);

        $page = $client->request('GET', '/moderate/traffic');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('tr[data-way="'.self::WAY.'"]'));
    }

    public function testARiderCannotOpenIt(): void
    {
        $client = static::createClient();
        $this->curator($client, false, []);
        $client->request('GET', '/moderate/traffic');

        self::assertResponseStatusCodeSame(403);
    }
}
