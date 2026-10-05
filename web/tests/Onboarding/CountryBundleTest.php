<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Onboarding;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CountryBundleTest extends KernelTestCase
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

    private function seedLiveXa(): void
    {
        $db = $this->db();
        $db->executeStatement("INSERT INTO country (code, name, subtype, bbox, labels, timezones, status, overture_release) VALUES ('XA', 'Xaland', 'region', '[50, 50, 52, 51]', '{\"en\":\"All Xaland\",\"fr\":\"Xaland (tout le pays)\"}', '{Etc/GMT-3}', 'live', '2026-08-19.0')");
        $db->executeStatement("INSERT INTO country_extract (slug, country_code) VALUES ('test/xaland', 'XA')");
        $db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, labels, created_at, updated_at) VALUES
             ('xa-west', 'West', ST_Multi(ST_GeomFromText('POLYGON((50 50,51 50,51 51,50 51,50 50))', 4326)), 7000.5, 'XA', 'XA-W', 4, 'overture', '{\"en\":\"West\",\"nl\":\"West-Xa\"}', NOW(), NOW()),
             ('xaland', 'Xaland', ST_Multi(ST_GeomFromText('POLYGON((50 50,52 50,52 51,50 51,50 50))', 4326)), 14000, 'XA', 'XA', 2, 'overture', '{}', NOW(), NOW())",
        );
    }

    private function tempFile(string $content): string
    {
        $path = sys_get_temp_dir().'/country-bundle-'.uniqid('', true).'.json';
        file_put_contents($path, $content);

        return $path;
    }

    public function testExportThenImportPlanCarriesTheExactRows(): void
    {
        self::bootKernel();
        $this->seedLiveXa();
        $export = $this->command('app:country:export', ['country' => 'XA']);
        $export->assertCommandIsSuccessful();
        $json = $export->getDisplay();
        /** @var array{regions: list<array{slug: string, geometry: array<string, mixed>}>} $bundle */
        $bundle = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        // Production has never seen XA.
        $this->db()->executeStatement("DELETE FROM region WHERE country_code = 'XA'");
        $this->db()->executeStatement("DELETE FROM country WHERE code = 'XA'");

        $this->command('app:country:import-plan', ['--file' => $this->tempFile($json)])->assertCommandIsSuccessful();

        $db = $this->db();
        self::assertSame(['status' => 'planned', 'name' => 'Xaland', 'subtype' => 'region', 'release' => '2026-08-19.0', 'fr' => 'Xaland (tout le pays)', 'tz' => '["Etc/GMT-3"]'],
            $db->fetchAssociative("SELECT status, name, subtype, overture_release AS release, labels->>'fr' AS fr, array_to_json(timezones)::text AS tz FROM country WHERE code = 'XA'"));
        self::assertSame(['test/xaland'], $db->fetchFirstColumn("SELECT slug FROM country_extract WHERE country_code = 'XA'"));
        self::assertSame('West-Xa', $db->fetchOne("SELECT labels->>'nl' FROM country_plan_region WHERE slug = 'xa-west'"));
        self::assertEquals(['xa-west' => 4, 'xaland' => 2], array_map('intval', $db->fetchAllKeyValue("SELECT slug, admin_level FROM country_plan_region WHERE country_code = 'XA'")));
        foreach ($bundle['regions'] as $r) {
            self::assertTrue((bool) $db->fetchOne(
                'SELECT ST_Equals(geom, ST_SetSRID(ST_GeomFromGeoJSON(:g), 4326)) FROM country_plan_region WHERE slug = :s',
                ['g' => json_encode($r['geometry'], \JSON_THROW_ON_ERROR), 's' => $r['slug']],
            ), $r['slug'].' geometry survives the round trip');
        }
    }

    public function testExportNeedsALiveCountry(): void
    {
        self::bootKernel();
        $this->db()->executeStatement("INSERT INTO country (code, name, subtype, status) VALUES ('XC', 'Xcland', 'region', 'seeded')");
        self::assertSame(1, $this->command('app:country:export', ['country' => 'XC'])->getStatusCode());
    }

    public function testATruncatedBundleWritesNothing(): void
    {
        self::bootKernel();
        $this->seedLiveXa();
        $json = $this->command('app:country:export', ['country' => 'XA'])->getDisplay();
        $this->db()->executeStatement("DELETE FROM region WHERE country_code = 'XA'");
        $this->db()->executeStatement("DELETE FROM country WHERE code = 'XA'");

        $tester = $this->command('app:country:import-plan', ['--file' => $this->tempFile(substr($json, 0, intdiv(\strlen($json), 2)))]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertFalse($this->db()->fetchOne("SELECT code FROM country WHERE code = 'XA'"));
        self::assertSame(1, $this->command('app:country:import-plan', ['--file' => $this->tempFile('')])->getStatusCode());
    }

    public function testImportPlanRefusesACountryAlreadyLiveHere(): void
    {
        self::bootKernel();
        $this->seedLiveXa();
        $json = $this->command('app:country:export', ['country' => 'XA'])->getDisplay();

        $tester = $this->command('app:country:import-plan', ['--file' => $this->tempFile($json)]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('XA is live', $tester->getDisplay());
    }

    public function testNonObjectLabelsAreReadAsEmptyAndWrittenAsAnObject(): void
    {
        self::bootKernel();
        $this->seedLiveXa();
        $this->db()->executeStatement("UPDATE country SET labels = '[\"x\"]'::jsonb WHERE code = 'XA'");
        $this->db()->executeStatement("UPDATE region SET labels = '\"s\"'::jsonb WHERE slug = 'xaland'");
        $json = $this->command('app:country:export', ['country' => 'XA'])->getDisplay();
        self::assertStringContainsString('"labels":{}', $json);
        $this->db()->executeStatement("DELETE FROM region WHERE country_code = 'XA'");
        $this->db()->executeStatement("DELETE FROM country WHERE code = 'XA'");

        $this->command('app:country:import-plan', ['--file' => $this->tempFile($json)])->assertCommandIsSuccessful();

        self::assertSame('object', $this->db()->fetchOne("SELECT jsonb_typeof(labels) FROM country WHERE code = 'XA'"));
        self::assertSame('object', $this->db()->fetchOne("SELECT jsonb_typeof(labels) FROM country_plan_region WHERE slug = 'xaland'"));
    }
}
