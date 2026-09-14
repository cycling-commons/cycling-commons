<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Command;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Boundaries we hold, loaded apart from the ones we run.
 *
 * @see docs/specs/moderation-and-contribution.md §11.1a
 */
final class DivisionsImportCommandTest extends KernelTestCase
{
    private string $dir;

    #[\Override]
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/divisions-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    #[\Override]
    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    /** @param list<array{iso: ?string, name: string, area: float, lon: float}> $rows */
    private function write(string $cc, array $rows): void
    {
        $lines = [];
        foreach ($rows as $r) {
            $x = $r['lon'];
            $lines[] = json_encode([
                'type' => 'Feature',
                'properties' => ['iso' => $r['iso'], 'name' => $r['name'], 'subtype' => 'region', 'release' => '2026-08-19.0', 'area_km2' => $r['area']],
                'geometry' => ['type' => 'Polygon', 'coordinates' => [[[$x, 40.0], [$x + 1, 40.0], [$x + 1, 41.0], [$x, 41.0], [$x, 40.0]]]],
            ], \JSON_THROW_ON_ERROR);
        }
        file_put_contents($this->dir.'/'.strtolower($cc).'.ndjson', implode("\n", $lines)."\n");
    }

    private function import(string ...$args): CommandTester
    {
        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:divisions:import'));
        $input = ['--dir' => $this->dir];
        foreach ($args as $a) {
            $input[$a] = true;
        }
        $tester->execute($input);

        return $tester;
    }

    public function testLoadsBoundariesAndTouchesNoRegion(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $regionsBefore = (int) $db->fetchOne('SELECT COUNT(*) FROM region');

        $this->write('QX', [
            ['iso' => 'QX-A', 'name' => 'Alpha', 'area' => 15000.0, 'lon' => 1.0],
            ['iso' => null, 'name' => 'Uninhabited Rock', 'area' => 0.2, 'lon' => 3.0],
        ]);

        $tester = $this->import();

        $tester->assertCommandIsSuccessful();
        self::assertSame(2, (int) $db->fetchOne("SELECT COUNT(*) FROM world_division WHERE country_code = 'QX'"));
        self::assertSame($regionsBefore, (int) $db->fetchOne('SELECT COUNT(*) FROM region'),
            'holding a boundary onboards nothing');
        self::assertSame('overture:2026-08-19.0', $db->fetchOne("SELECT source FROM world_division WHERE name = 'Alpha'"));
    }

    public function testAReimportRefreshesInPlaceEvenWithoutAnIsoCode(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        // No ISO code, as Overture ships the uninhabited ones. Two nulls never
        // conflict in a unique index, which is why the key is the name.
        $this->write('QY', [['iso' => null, 'name' => 'Plazas', 'area' => 0.5, 'lon' => 5.0]]);
        $this->import();
        $this->write('QY', [['iso' => null, 'name' => 'Plazas', 'area' => 0.7, 'lon' => 5.0]]);
        $this->import();

        self::assertSame(1, (int) $db->fetchOne("SELECT COUNT(*) FROM world_division WHERE country_code = 'QY'"),
            'a second release is the same row, not a second copy');
        self::assertEqualsWithDelta(0.7, (float) $db->fetchOne("SELECT area_km2 FROM world_division WHERE country_code = 'QY'"), 0.001);
    }

    /**
     * Two places, one name.
     *
     * Malta has a council called Ir-Rabat on each island. Keyed on the name,
     * the first full import kept one and overwrote the other; the ISO code is
     * what tells them apart (2026-09-14).
     */
    public function testTwoDivisionsSharingANameAreTwoRows(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $this->write('QW', [
            ['iso' => 'QW-45', 'name' => 'Ir-Rabat', 'area' => 26.0, 'lon' => 9.0],
            ['iso' => 'QW-46', 'name' => 'Ir-Rabat', 'area' => 3.0, 'lon' => 11.0],
        ]);

        $this->import()->assertCommandIsSuccessful();

        self::assertSame(2, (int) $db->fetchOne("SELECT COUNT(*) FROM world_division WHERE country_code = 'QW'"));
    }

    public function testADryRunWritesNothing(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $this->write('QZ', [['iso' => 'QZ-A', 'name' => 'Zed', 'area' => 1.0, 'lon' => 7.0]]);

        $this->import('--dry-run')->assertCommandIsSuccessful();

        self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM world_division WHERE country_code = 'QZ'"));
    }
}
