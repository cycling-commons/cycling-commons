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
             ('xa-west', 'West', ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON('{\"type\":\"Polygon\",\"coordinates\":[[[50.98765432109876543,50.12345678901234567],[51.1234567890123456,50.00000000000001],[51.333333333333333,51.987654321098765],[50.7777777777777777,51.0000000000000009],[50.98765432109876543,50.12345678901234567]]]}'), 4326)), 7000.5, 'XA', 'XA-W', 4, 'overture', '{\"en\":\"West\",\"nl\":\"West-Xa\"}', NOW(), NOW()),
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
        /** @var array<string, string> $before */
        $before = $this->db()->fetchAllKeyValue("SELECT slug, encode(ST_AsEWKB(geom), 'hex') FROM region WHERE country_code = 'XA'");
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
        self::assertSame($before, $db->fetchAllKeyValue("SELECT slug, encode(ST_AsEWKB(geom), 'hex') FROM country_plan_region WHERE country_code = 'XA'"), 'geometry is byte-identical after the round trip');
        self::assertSame(['iso_code' => 'XA-W', 'name' => 'West', 'area_km2' => '7000.5', 'en' => 'West'],
            $db->fetchAssociative("SELECT iso_code, name, area_km2::text AS area_km2, labels->>'en' AS en FROM country_plan_region WHERE slug = 'xa-west'"));
        self::assertSame(['50', '50', '52', '51'], array_map(static fn ($v): string => (string) (0 + $v), json_decode((string) $db->fetchOne("SELECT bbox::text FROM country WHERE code = 'XA'"), true, 512, \JSON_THROW_ON_ERROR)));
        self::assertSame('All Xaland', $db->fetchOne("SELECT labels->>'en' FROM country WHERE code = 'XA'"));
        self::assertCount(2, $bundle['regions']);
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

    private function exportedXaThenForgotten(): string
    {
        $this->seedLiveXa();
        $json = $this->command('app:country:export', ['country' => 'XA'])->getDisplay();
        $this->db()->executeStatement("DELETE FROM region WHERE country_code = 'XA'");
        $this->db()->executeStatement("DELETE FROM country_extract WHERE country_code = 'XA'");
        $this->db()->executeStatement("DELETE FROM country WHERE code = 'XA'");

        return $json;
    }

    public function testAWrongVersionOrAMissingKeyWritesNothing(): void
    {
        self::bootKernel();
        $json = $this->exportedXaThenForgotten();
        /** @var array<string, mixed> $bundle */
        $bundle = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $wrongVersion = ['version' => 2] + $bundle;
        $noExtracts = $bundle;
        unset($noExtracts['extracts']);
        $nestedLabel = $bundle;
        $nestedLabel['country']['labels'] = ['en' => ['x']];

        foreach ([$wrongVersion, $noExtracts, $nestedLabel] as $bad) {
            $tester = $this->command('app:country:import-plan', ['--file' => $this->tempFile(json_encode($bad, \JSON_THROW_ON_ERROR))]);
            self::assertSame(1, $tester->getStatusCode());
            self::assertStringContainsString('Not a country bundle', $tester->getDisplay());
        }
        self::assertFalse($this->db()->fetchOne("SELECT code FROM country WHERE code = 'XA'"));
    }

    public function testUnreadableAndEmptyInputSayWhy(): void
    {
        self::bootKernel();
        $missing = $this->command('app:country:import-plan', ['--file' => '/nonexistent/bundle.json']);
        self::assertSame(1, $missing->getStatusCode());
        self::assertStringContainsString('cannot read /nonexistent/bundle.json', $missing->getDisplay());
        $empty = $this->command('app:country:import-plan', ['--file' => $this->tempFile('')]);
        self::assertStringContainsString('empty input', $empty->getDisplay());
    }

    public function testAnExtractOwnedByAnotherCountryIsRefusedInOneLine(): void
    {
        self::bootKernel();
        $json = $this->exportedXaThenForgotten();
        $this->db()->executeStatement("INSERT INTO country (code, name, subtype, status) VALUES ('XD', 'Xdland', 'region', 'live')");
        $this->db()->executeStatement("INSERT INTO country_extract (slug, country_code) VALUES ('test/xaland', 'XD')");

        $tester = $this->command('app:country:import-plan', ['--file' => $this->tempFile($json)]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('extract test/xaland already belongs to XD', $tester->getDisplay());
        self::assertStringNotContainsString('SQLSTATE', $tester->getDisplay());
        self::assertFalse($this->db()->fetchOne("SELECT code FROM country WHERE code = 'XA'"));
    }

    public function testApplyAcceptsAnImportedPlan(): void
    {
        self::bootKernel();
        $json = $this->exportedXaThenForgotten();
        $this->command('app:country:import-plan', ['--file' => $this->tempFile($json)])->assertCommandIsSuccessful();

        $this->command('app:country:apply', ['country' => 'XA'])->assertCommandIsSuccessful();

        self::assertSame('seeded', $this->db()->fetchOne("SELECT status FROM country WHERE code = 'XA'"));
        self::assertSame(2, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM region WHERE country_code = 'XA'"));
    }
}
