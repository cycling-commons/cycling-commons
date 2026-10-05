<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Onboarding;

use App\Catalog\CountryTimezones;
use App\Catalog\RegionRegistryProvider;
use App\Entity\User;
use App\Onboarding\Countries;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * XA is planned with two provinces and its outline; XB is live and touches
 * XA's east province, so apply must make them neighbours and re-stamp both.
 */
final class CountryCommandsTest extends KernelTestCase
{
    private function db(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    /** @param array<string, mixed> $input */
    private function command(string $name, array $input): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find($name));
        $tester->execute($input);

        return $tester;
    }

    private function plan(string $cc, string $slug, string $wkt, int $admin, string $iso, float $area = 12000): void
    {
        $this->db()->executeStatement(
            'INSERT INTO country_plan_region (country_code, slug, iso_code, name, labels, admin_level, area_km2, geom)
             VALUES (:cc, :slug, :iso, :name, CAST(:labels AS jsonb), :admin, :area, ST_Multi(ST_GeomFromText(:wkt, 4326)))',
            ['cc' => $cc, 'slug' => $slug, 'iso' => $iso, 'name' => ucfirst($slug), 'admin' => $admin, 'wkt' => $wkt, 'area' => $area,
                'labels' => json_encode(['en' => ucfirst($slug), 'fr' => 'Fr '.$slug], \JSON_THROW_ON_ERROR)],
        );
    }

    /** Plans $cc (XA: xa-west, xa-east, xaland, extract test/xaland) beside live XB. */
    private function seed(string $cc = 'XA'): int
    {
        $db = $this->db();
        $land = ucfirst(strtolower($cc)).'land';
        $db->executeStatement(
            "INSERT INTO country (code, name, subtype, labels, status, planned_at) VALUES (:cc, :name, 'region', CAST(:labels AS jsonb), 'planned', NOW())",
            ['cc' => $cc, 'name' => $land, 'labels' => json_encode(['en' => 'All '.$land], \JSON_THROW_ON_ERROR)],
        );
        $db->executeStatement('INSERT INTO country_extract (slug, country_code) VALUES (:slug, :cc)', ['slug' => 'test/'.strtolower($land), 'cc' => $cc]);
        $prefix = strtolower($cc);
        $this->plan($cc, $prefix.'-west', 'POLYGON((50 50,51 50,51 51,50 51,50 50))', 4, $cc.'-W');
        $this->plan($cc, $prefix.'-east', 'POLYGON((51 50,52 50,52 51,51 51,51 50))', 4, $cc.'-E');
        $this->plan($cc, strtolower($land), 'POLYGON((50 50,52 50,52 51,50 51,50 50))', 2, $cc);
        $db->executeStatement("INSERT INTO country (code, name, subtype, status) VALUES ('XB', 'Xbland', 'region', 'live')");
        $db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES ('xb-north', 'XB North', ST_Multi(ST_GeomFromText('POLYGON((51 51,52 51,52 52,51 52,51 51))', 4326)), 12000, 'XB', 'XB-N', 4, 'test', NOW(), NOW())",
        );
        $user = (new User())->setEmail('apply-'.uniqid('', true).'@test.test');
        $user->setPassword('x');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();

        return (int) $db->fetchOne(
            "INSERT INTO submission (type, letter, user_id, status, title, geom, country_code, changes, payload, created_at)
             VALUES ('new', 'B', :u, 'pending', 'Tap', ST_SetSRID(ST_MakePoint(50.5, 50.5), 4326), '', '{}', '{}', NOW()) RETURNING id",
            ['u' => (int) $user->getId()],
        );
    }

    public function testApplyTurnsThePlanIntoRegionsNeighboursAndStamps(): void
    {
        self::bootKernel();
        $submission = $this->seed();

        $tester = $this->command('app:country:apply', ['country' => 'xa']);
        $tester->assertCommandIsSuccessful();

        $db = $this->db();
        self::assertSame('seeded', $db->fetchOne("SELECT status FROM country WHERE code = 'XA'"));
        self::assertSame(3, (int) $db->fetchOne("SELECT COUNT(*) FROM region WHERE country_code = 'XA'"));
        self::assertSame('Fr xa-west', $db->fetchOne("SELECT labels->>'fr' FROM region WHERE slug = 'xa-west'"));
        $xbNorth = (int) $db->fetchOne("SELECT id FROM region WHERE slug = 'xb-north'");
        /** @var string $adj */
        $adj = $db->fetchOne("SELECT to_json(adj)::text FROM region WHERE slug = 'xa-east'");
        self::assertContains($xbNorth, json_decode($adj, true, 512, \JSON_THROW_ON_ERROR), 'the cross-border pair is adjacent');
        self::assertSame('XA', $db->fetchOne('SELECT country_code FROM submission WHERE id = ?', [$submission]));
        self::assertStringContainsString('neighbours: XB', $tester->getDisplay());
    }

    public function testApplyTwiceIsIdempotent(): void
    {
        self::bootKernel();
        $this->seed();
        $this->command('app:country:apply', ['country' => 'XA'])->assertCommandIsSuccessful();
        $seededAt = $this->db()->fetchOne("SELECT seeded_at FROM country WHERE code = 'XA'");

        $this->command('app:country:apply', ['country' => 'XA'])->assertCommandIsSuccessful();

        self::assertSame(3, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM region WHERE country_code = 'XA'"));
        self::assertSame('seeded', $this->db()->fetchOne("SELECT status FROM country WHERE code = 'XA'"));
        self::assertSame($seededAt, $this->db()->fetchOne("SELECT seeded_at FROM country WHERE code = 'XA'"));
    }

    public function testApplyFillsEmptyTimezonesAndKeepsAStoredList(): void
    {
        self::bootKernel();
        $this->seed('DK');
        $this->command('app:country:apply', ['country' => 'DK'])->assertCommandIsSuccessful();
        self::assertSame('["Europe/Copenhagen"]', $this->db()->fetchOne("SELECT array_to_json(timezones)::text FROM country WHERE code = 'DK'"));

        $this->db()->executeStatement("UPDATE country SET timezones = '{Europe/Copenhagen,Atlantic/Faroe}' WHERE code = 'DK'");
        $this->command('app:country:apply', ['country' => 'DK'])->assertCommandIsSuccessful();
        self::assertSame(
            '["Europe/Copenhagen","Atlantic/Faroe"]',
            $this->db()->fetchOne("SELECT array_to_json(timezones)::text FROM country WHERE code = 'DK'"),
            'a stored list is never overwritten',
        );
    }

    public function testApplyRefusesALiveCountryAndAMissingPlan(): void
    {
        self::bootKernel();
        $live = $this->command('app:country:apply', ['country' => 'DE']);
        self::assertSame(1, $live->getStatusCode());
        self::assertStringContainsString('DE is live', $live->getDisplay());

        $this->db()->executeStatement("INSERT INTO country (code, name, subtype, status) VALUES ('XC', 'Xcland', 'region', 'planned')");
        $empty = $this->command('app:country:apply', ['country' => 'XC']);
        self::assertSame(1, $empty->getStatusCode());
        self::assertStringContainsString('no plan rows', $empty->getDisplay());
    }

    public function testMarkLiveNeedsSeeded(): void
    {
        self::bootKernel();
        $this->seed();
        self::assertSame(1, $this->command('app:country:mark-live', ['country' => 'XA'])->getStatusCode(), 'planned is not seeded');
        $this->command('app:country:apply', ['country' => 'XA'])->assertCommandIsSuccessful();

        $this->command('app:country:mark-live', ['country' => 'XA'])->assertCommandIsSuccessful();

        self::assertSame('live', $this->db()->fetchOne("SELECT status FROM country WHERE code = 'XA'"));
        self::assertNotNull($this->db()->fetchOne("SELECT live_at FROM country WHERE code = 'XA'"));
    }

    public function testStatusPrintsOneLinePerCountry(): void
    {
        self::bootKernel();
        $this->seed();
        $this->command('app:country:apply', ['country' => 'XA'])->assertCommandIsSuccessful();

        $one = $this->command('app:country:status', ['country' => 'XA']);
        $one->assertCommandIsSuccessful();
        self::assertMatchesRegularExpression('/XA\s+seeded\s+\S+.*\s3\s+test\/xaland/', $one->getDisplay());

        $all = $this->command('app:country:status', []);
        self::assertStringContainsString('DE', $all->getDisplay());
        self::assertStringContainsString('XA', $all->getDisplay());
    }

    public function testLabelEditsARegionOrACountry(): void
    {
        self::bootKernel();
        $this->seed();
        $this->command('app:country:apply', ['country' => 'XA'])->assertCommandIsSuccessful();

        $this->command('app:country:label', ['target' => 'xa-west', 'locale' => 'nl', 'text' => 'Xa-West'])->assertCommandIsSuccessful();
        $this->command('app:country:label', ['target' => 'XA', 'locale' => 'nl', 'text' => 'Heel Xaland'])->assertCommandIsSuccessful();

        self::assertSame('Xa-West', $this->db()->fetchOne("SELECT labels->>'nl' FROM region WHERE slug = 'xa-west'"));
        self::assertSame('Fr xa-west', $this->db()->fetchOne("SELECT labels->>'fr' FROM region WHERE slug = 'xa-west'"), 'other locales stay');
        self::assertSame('Heel Xaland', $this->db()->fetchOne("SELECT labels->>'nl' FROM country WHERE code = 'XA'"));
        self::assertSame(2, $this->command('app:country:label', ['target' => 'xa-west', 'locale' => 'it', 'text' => 'x'])->getStatusCode());
        self::assertSame(1, $this->command('app:country:label', ['target' => 'no-such-slug', 'locale' => 'en', 'text' => 'x'])->getStatusCode());
    }

    public function testApplyRefusesASlugThatBelongsToAnotherCountry(): void
    {
        self::bootKernel();
        $this->seed();
        $this->db()->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES ('xa-west', 'Taken', ST_Multi(ST_GeomFromText('POLYGON((60 60,61 60,61 61,60 61,60 60))', 4326)), 1, 'XB', 'XB-T', 4, 'test', NOW(), NOW())",
        );

        $tester = $this->command('app:country:apply', ['country' => 'XA']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('xa-west', $tester->getDisplay());
        self::assertSame('planned', $this->db()->fetchOne("SELECT status FROM country WHERE code = 'XA'"));
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM region WHERE country_code = 'XA'"));
        self::assertSame('XB', $this->db()->fetchOne("SELECT country_code FROM region WHERE slug = 'xa-west'"));
    }

    public function testLabelWritesAnObjectOverAnEmptyArray(): void
    {
        self::bootKernel();
        $this->seed();
        $this->command('app:country:apply', ['country' => 'XA'])->assertCommandIsSuccessful();
        $this->db()->executeStatement("UPDATE region SET labels = '[]'::jsonb WHERE slug = 'xa-west'");

        $this->command('app:country:label', ['target' => 'xa-west', 'locale' => 'de', 'text' => 'Xa-Westen'])->assertCommandIsSuccessful();

        self::assertSame('object', $this->db()->fetchOne("SELECT jsonb_typeof(labels) FROM region WHERE slug = 'xa-west'"));
        self::assertSame('Xa-Westen', $this->db()->fetchOne("SELECT labels->>'de' FROM region WHERE slug = 'xa-west'"));
    }

    public function testApplyRestampsANeighboursSubmissionIntoASmallerNewRegion(): void
    {
        self::bootKernel();
        $this->seed();
        $this->plan('XA', 'xa-cape', 'POLYGON((51.2 51.2,51.4 51.2,51.4 51.4,51.2 51.4,51.2 51.2))', 4, 'XA-C', 500);
        $db = $this->db();
        $xbNorth = (int) $db->fetchOne("SELECT id FROM region WHERE slug = 'xb-north'");
        $userId = (int) $db->fetchOne('SELECT user_id FROM submission ORDER BY id DESC LIMIT 1');
        $submission = (int) $db->fetchOne(
            "INSERT INTO submission (type, letter, user_id, status, title, geom, region_id, country_code, changes, payload, created_at)
             VALUES ('new', 'B', :u, 'pending', 'Cape tap', ST_SetSRID(ST_MakePoint(51.3, 51.3), 4326), :r, 'XB', '{}', '{}', NOW()) RETURNING id",
            ['u' => $userId, 'r' => $xbNorth],
        );

        $this->command('app:country:apply', ['country' => 'XA'])->assertCommandIsSuccessful();

        self::assertSame(
            ['region_id' => (int) $db->fetchOne("SELECT id FROM region WHERE slug = 'xa-cape'"), 'country_code' => 'XA'],
            array_map(static fn ($v) => \is_numeric($v) ? (int) $v : $v, (array) $db->fetchAssociative('SELECT region_id, country_code FROM submission WHERE id = ?', [$submission])),
            'the smaller new region wins over the neighbour that stamped it',
        );
    }

    public function testAFailureInsideApplyRollsEverythingBack(): void
    {
        self::bootKernel();
        $this->seed();
        $this->plan('XA', 'xa-middle', 'POLYGON((50.5 50,51.5 50,51.5 51,50.5 51,50.5 50))', 4, 'XA-M');

        $tester = $this->command('app:country:apply', ['country' => 'XA']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('overlap', $tester->getDisplay());
        self::assertSame('planned', $this->db()->fetchOne("SELECT status FROM country WHERE code = 'XA'"));
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM region WHERE country_code = 'XA'"));
    }

    public function testApplyReDerivesTheBandAndLeavesFarRowsAlone(): void
    {
        self::bootKernel();
        $this->seed();
        $db = $this->db();
        $xfFar = (int) $db->fetchOne(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES ('xf-far', 'XF Far', ST_Multi(ST_GeomFromText('POLYGON((10 10,11 10,11 11,10 11,10 10))', 4326)), 12000, 'XF', 'XF-F', 4, 'test', NOW(), NOW()) RETURNING id",
        );
        // A catalog-wide rewrite touches these (NULL-then-reassign, a fresh surface profile, a rider's stale base).
        $farItem = (int) $db->fetchOne(
            "INSERT INTO item (letter, name, geom, region_id, country_code, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('B', 'Far tap', ST_SetSRID(ST_MakePoint(10.5, 10.5), 4326), :r, 'XF', 'unverified', 'user', 'apply:far', '{}', NOW(), NOW()) RETURNING id",
            ['r' => $xfFar],
        );
        $nearItem = (int) $db->fetchOne(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('B', 'Near tap', ST_SetSRID(ST_MakePoint(50.5, 50.5), 4326), '', 'unverified', 'user', 'apply:near', '{}', NOW(), NOW()) RETURNING id",
        );
        $farHeat = (int) $db->fetchOne(
            "INSERT INTO heat_point (geom, weight, source, computed_at, region_id) VALUES (ST_SetSRID(ST_MakePoint(10.5, 10.5), 4326), 1, 'test', NOW(), :r) RETURNING id",
            ['r' => $xfFar],
        );
        $nearHeat = (int) $db->fetchOne(
            "INSERT INTO heat_point (geom, weight, source, computed_at) VALUES (ST_SetSRID(ST_MakePoint(51.5, 50.5), 4326), 1, 'test', NOW()) RETURNING id",
        );
        $farRoute = (int) $db->fetchOne(
            "INSERT INTO recommended_route (name, geom, region_id, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('Far loop', ST_SetSRID(ST_GeomFromText('LINESTRING(10.4 10.4,10.6 10.6)'), 4326), :r, 'published', 'user', 'apply:far', '{\"surfaces\":{\"covered\":50,\"parts\":[]}}', NOW(), NOW()) RETURNING id",
            ['r' => $xfFar],
        );
        $farUser = (new User())->setEmail('apply-far-'.uniqid('', true).'@test.test');
        $farUser->setPassword('x');
        $nearUser = (new User())->setEmail('apply-near-'.uniqid('', true).'@test.test');
        $nearUser->setPassword('x');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($farUser);
        $em->persist($nearUser);
        $em->flush();
        $db->executeStatement(
            "UPDATE users SET base_point = ST_SetSRID(ST_MakePoint(10.5, 10.5), 4326), base_radius_km = 5, base_region_ids = '[999999]', base_country_codes = '[\"ZZ\"]' WHERE id = ?",
            [(int) $farUser->getId()],
        );
        $db->executeStatement(
            "UPDATE users SET base_point = ST_SetSRID(ST_MakePoint(50.5, 50.5), 4326), base_radius_km = 5, base_region_ids = '[]', base_country_codes = '[]' WHERE id = ?",
            [(int) $nearUser->getId()],
        );
        $ctid = static fn (string $table, int $id): string => (string) $db->fetchOne("SELECT ctid::text FROM {$table} WHERE id = ?", [$id]);
        $before = [$ctid('item', $farItem), $ctid('heat_point', $farHeat), $ctid('recommended_route', $farRoute), $ctid('users', (int) $farUser->getId())];

        $this->command('app:country:apply', ['country' => 'XA'])->assertCommandIsSuccessful();

        $xaWest = (int) $db->fetchOne("SELECT id FROM region WHERE slug = 'xa-west'");
        $xaEast = (int) $db->fetchOne("SELECT id FROM region WHERE slug = 'xa-east'");
        self::assertSame($xaWest, (int) $db->fetchOne('SELECT region_id FROM item WHERE id = ?', [$nearItem]), 'an item in the band is re-derived');
        self::assertSame($xaEast, (int) $db->fetchOne('SELECT region_id FROM heat_point WHERE id = ?', [$nearHeat]), 'a heat point in the band is re-derived');
        self::assertContains($xaWest, json_decode((string) $db->fetchOne('SELECT base_region_ids FROM users WHERE id = ?', [(int) $nearUser->getId()]), true, 512, \JSON_THROW_ON_ERROR), 'a rider base in the band is re-derived');
        self::assertSame(
            $before,
            [$ctid('item', $farItem), $ctid('heat_point', $farHeat), $ctid('recommended_route', $farRoute), $ctid('users', (int) $farUser->getId())],
            'rows far from XA and its neighbours are not written',
        );
        self::assertSame($xfFar, (int) $db->fetchOne('SELECT region_id FROM item WHERE id = ?', [$farItem]));
    }

    public function testApplyDoesNotRewriteARowWhoseRegionStays(): void
    {
        self::bootKernel();
        $this->seed();
        $this->command('app:country:apply', ['country' => 'XA'])->assertCommandIsSuccessful();
        $db = $this->db();
        $item = (int) $db->fetchOne(
            "INSERT INTO item (letter, name, geom, region_id, country_code, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('B', 'Kept tap', ST_SetSRID(ST_MakePoint(50.5, 50.5), 4326), (SELECT id FROM region WHERE slug = 'xa-west'), 'XA', 'unverified', 'user', 'apply:kept', '{}', NOW(), NOW()) RETURNING id",
        );
        $before = (string) $db->fetchOne('SELECT ctid::text FROM item WHERE id = ?', [$item]);

        $this->command('app:country:apply', ['country' => 'XA'])->assertCommandIsSuccessful();

        self::assertSame($before, (string) $db->fetchOne('SELECT ctid::text FROM item WHERE id = ?', [$item]), 'no NULL-then-reassign');
    }

    public function testApplyGivesUpOnAHeldLockAndWritesNothing(): void
    {
        self::bootKernel();
        $this->seed();
        // A second session, outside DAMA's per-test transaction, holds the lock.
        $other = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? ''));
        $other->beginTransaction();
        $other->executeStatement('LOCK TABLE heat_point IN ACCESS EXCLUSIVE MODE');
        try {
            $tester = $this->command('app:country:apply', ['country' => 'XA']);
        } finally {
            $other->rollBack();
            $other->close();
        }

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('waited 10 s for a lock', $tester->getDisplay());
        self::assertSame('planned', $this->db()->fetchOne("SELECT status FROM country WHERE code = 'XA'"));
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM region WHERE country_code = 'XA'"));
    }

    public function testMarkSeededNeverDemotesALiveCountry(): void
    {
        self::bootKernel();
        $countries = new Countries($this->db());

        try {
            $countries->markSeeded('DE');
            self::fail('a live country was marked seeded');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('DE', $e->getMessage());
        }
        self::assertSame('live', $this->db()->fetchOne("SELECT status FROM country WHERE code = 'DE'"));
    }

    public function testTheMapShowsACountryOnlyOnceItIsLive(): void
    {
        self::bootKernel();
        $this->seed();
        $db = $this->db();
        $db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES ('xf-rowless', 'XF', ST_Multi(ST_GeomFromText('POLYGON((10 10,11 10,11 11,10 11,10 10))', 4326)), 12000, 'XF', 'XF-R', 4, 'test', NOW(), NOW())",
        );
        $this->command('app:country:apply', ['country' => 'XA'])->assertCommandIsSuccessful();
        $db->executeStatement("UPDATE country SET timezones = '{Etc/GMT-3}' WHERE code = 'XA'");
        $registry = static::getContainer()->get(RegionRegistryProvider::class);
        $timezones = static::getContainer()->get(CountryTimezones::class);
        $slugs = static fn (): array => array_column($registry->all(), 'slug');

        self::assertNotContains('xa-west', $slugs(), 'a seeded country is not on the map');
        self::assertContains('xb-north', $slugs(), 'a live country is');
        self::assertContains('xf-rowless', $slugs(), 'a region without a country row stays visible');
        self::assertArrayNotHasKey('Etc/GMT-3', $timezones->map());
        foreach ($registry->all() as $region) {
            if ('xb-north' === $region['slug']) {
                self::assertSame([], $region['adj'], 'a hidden neighbour is not named');
            }
        }

        $this->command('app:country:mark-live', ['country' => 'XA'])->assertCommandIsSuccessful();

        self::assertContains('xa-west', $slugs());
        self::assertContains('xa-east', $slugs());
        self::assertSame('XA', $timezones->map()['Etc/GMT-3'] ?? null);
    }
}
