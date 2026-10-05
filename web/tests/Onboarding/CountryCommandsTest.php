<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Onboarding;

use App\Entity\User;
use Doctrine\DBAL\Connection;
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

    private function plan(string $cc, string $slug, string $wkt, int $admin, string $iso): void
    {
        $this->db()->executeStatement(
            'INSERT INTO country_plan_region (country_code, slug, iso_code, name, labels, admin_level, area_km2, geom)
             VALUES (:cc, :slug, :iso, :name, CAST(:labels AS jsonb), :admin, 12000, ST_Multi(ST_GeomFromText(:wkt, 4326)))',
            ['cc' => $cc, 'slug' => $slug, 'iso' => $iso, 'name' => ucfirst($slug), 'admin' => $admin, 'wkt' => $wkt,
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
}
