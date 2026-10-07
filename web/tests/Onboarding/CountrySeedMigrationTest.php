<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Onboarding;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261005120100;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The seed turns today's code lists into rows: 19 live countries, today's
 * onboarded extracts, their timezones, and every region label the catalogues held.
 */
final class CountrySeedMigrationTest extends KernelTestCase
{
    /** web/assets/map/scope.js TZ_COUNTRY on 2026-10-05, frozen (Task 4 deletes it). */
    private const array TZ_COUNTRY_ON_2026_10_05 = [
        'Europe/Brussels' => 'BE', 'Europe/Amsterdam' => 'NL', 'Europe/Berlin' => 'DE', 'Europe/Busingen' => 'DE',
        'Europe/Luxembourg' => 'LU', 'Europe/Paris' => 'FR', 'Europe/Zurich' => 'CH', 'Europe/London' => 'GB',
        'Europe/Belfast' => 'GB', 'Europe/Rome' => 'IT', 'Europe/Madrid' => 'ES', 'Atlantic/Canary' => 'ES',
        'Africa/Ceuta' => 'ES', 'Asia/Tokyo' => 'JP', 'Australia/Sydney' => 'AU', 'Australia/Melbourne' => 'AU',
        'Australia/Brisbane' => 'AU', 'Australia/Perth' => 'AU', 'Australia/Adelaide' => 'AU', 'Australia/Hobart' => 'AU',
        'Australia/Darwin' => 'AU', 'Australia/Canberra' => 'AU', 'Australia/Broken_Hill' => 'AU', 'Australia/Lindeman' => 'AU',
        'Australia/Lord_Howe' => 'AU', 'Australia/Eucla' => 'AU', 'America/Los_Angeles' => 'US', 'America/Denver' => 'US',
        'Europe/Ljubljana' => 'SI', 'Africa/Kigali' => 'RW', 'Africa/Johannesburg' => 'ZA', 'America/Bogota' => 'CO',
        'America/Santiago' => 'CL', 'Pacific/Easter' => 'CL', 'Pacific/Auckland' => 'NZ', 'Pacific/Chatham' => 'NZ',
        'America/Vancouver' => 'CA', 'America/Toronto' => 'CA', 'America/Montreal' => 'CA',
    ];

    /** pipeline/coverage/regions.py ONBOARDED_REGIONS on 2026-10-05, frozen. */
    private const array ONBOARDED_ON_2026_10_05 = [
        'africa/rwanda', 'africa/south-africa', 'asia/japan', 'australia-oceania/australia',
        'australia-oceania/new-zealand', 'europe/belgium', 'europe/france', 'europe/germany',
        'europe/great-britain', 'europe/ireland-and-northern-ireland', 'europe/italy', 'europe/luxembourg',
        'europe/netherlands', 'europe/slovenia', 'europe/spain', 'europe/switzerland',
        'north-america/canada/british-columbia', 'north-america/canada/quebec', 'north-america/us/california',
        'north-america/us/colorado', 'south-america/chile', 'south-america/colombia',
    ];

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        // DoctrineMigrations is not autoloaded; the constants below need the class.
        require_once \dirname(__DIR__, 2).'/migrations/Version20261005120100.php';
    }

    private function db(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    public function testTheNineteenCountriesAreLive(): void
    {
        self::bootKernel();
        /** @var list<string> $codes */
        $codes = $this->db()->fetchFirstColumn("SELECT code FROM country WHERE status = 'live' ORDER BY code");
        self::assertSame(
            ['AU', 'BE', 'CA', 'CH', 'CL', 'CO', 'DE', 'ES', 'FR', 'GB', 'IT', 'JP', 'LU', 'NL', 'NZ', 'RW', 'SI', 'US', 'ZA'],
            array_map('trim', $codes),
        );
        self::assertSame('All Germany', $this->db()->fetchOne("SELECT labels->>'en' FROM country WHERE code = 'DE'"));
    }

    public function testTheOnboardedExtractsAreTodaysList(): void
    {
        self::bootKernel();
        /** @var list<string> $slugs */
        $slugs = $this->db()->fetchFirstColumn(
            "SELECT e.slug FROM country_extract e JOIN country c ON c.code = e.country_code
              WHERE c.status IN ('seeded', 'live') ORDER BY e.slug",
        );
        self::assertSame(self::ONBOARDED_ON_2026_10_05, $slugs);
        self::assertSame('ES', trim((string) $this->db()->fetchOne("SELECT country_code FROM country_extract WHERE slug = 'europe/spain'")));
        self::assertSame('GB', trim((string) $this->db()->fetchOne("SELECT country_code FROM country_extract WHERE slug = 'europe/ireland-and-northern-ireland'")));
    }

    public function testTheTimezonesInvertTodaysMap(): void
    {
        self::bootKernel();
        $map = [];
        foreach ($this->db()->fetchAllAssociative('SELECT code, array_to_json(timezones)::text AS zones FROM country') as $row) {
            /** @var list<string> $zones */
            $zones = json_decode((string) $row['zones'], true, 512, \JSON_THROW_ON_ERROR);
            foreach ($zones as $zone) {
                $map[$zone] = trim((string) $row['code']);
            }
        }
        $expected = self::TZ_COUNTRY_ON_2026_10_05;
        ksort($expected);
        ksort($map);
        self::assertSame($expected, $map);
    }

    public function testLabelsLandOnTheRegionRowsAndTheSeedReplays(): void
    {
        self::bootKernel();
        $db = $this->db();
        $db->executeStatement(
            "INSERT INTO region (slug, name, country_code, admin_level, created_at, updated_at)
             VALUES ('bayern', 'Bavaria', 'DE', 4, NOW(), NOW())",
        );

        $migration = new Version20261005120100($db, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        /** @var array<string, string> $labels */
        $labels = json_decode((string) $db->fetchOne("SELECT labels::text FROM region WHERE slug = 'bayern'"), true, 512, \JSON_THROW_ON_ERROR);
        // jsonb does not keep key order.
        $expected = Version20261005120100::REGION_LABELS['bayern'];
        ksort($expected);
        ksort($labels);
        self::assertSame($expected, $labels);
        self::assertSame(19, (int) $db->fetchOne('SELECT COUNT(*) FROM country'), 'a replay adds nothing');
    }
}
