<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\Region;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Seeding reviewed mountain passes.
 *
 * The interesting behaviour is not "it inserts a row". It is the four ways this
 * command refuses to make a mess: a DROP row is a hiking trail and must never
 * be seedable; a side must own a ref that survives a re-run; two sides of one
 * col must not collapse into one name and silently overwrite each other; and a
 * climb outside every onboarded region must not be written where nobody can
 * find it.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction.
 */
final class SeedClimbsCommandTest extends KernelTestCase
{
    private function tester(): CommandTester
    {
        return new CommandTester(
            (new Application(self::bootKernel()))->find('app:catalog:seed-climbs'),
        );
    }

    /** A region the seeded points fall inside, or every row is "regionless". */
    private function seedRegion(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new Region())->setSlug('seed-climb-land')->setName('Climb Land')->setCountryCode('BE')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[4.0,49.5],[6.5,49.5],[6.5,51.0],[4.0,51.0],[4.0,49.5]]]]}'));
        $em->flush();
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function artifact(array $rows): string
    {
        $path = sys_get_temp_dir().'/climb-review-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, json_encode($rows, \JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * A line inside the test region, summit FIRST — the harvester walks down
     * from the col, which is why the command reverses it.
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function line(float $lngOffset = 0.0): array
    {
        return [
            [50.40, 5.10 + $lngOffset], [50.39, 5.11 + $lngOffset],
            [50.38, 5.12 + $lngOffset], [50.37, 5.13 + $lngOffset],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'cc' => 'BE', 'name' => 'Test Pass', 'qid' => 'Q1', 'side_index' => 0,
            'verdict' => 'KEEP', 'foot_place' => 'Testville', 'length_m' => 4000,
            'trace' => ['unpaved_pct' => 0.0, 'road' => 'N1'],
            'line' => self::line(),
        ];
    }

    /** @return array{name: string, ref: string, attrs: array<string, mixed>}|null */
    private function stored(string $name): ?array
    {
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $row = $db->fetchAssociative(
            "SELECT name, source_ref, attributes FROM item WHERE letter = 'N' AND name = :n",
            ['n' => $name],
        );

        return false === $row ? null : [
            'name' => (string) $row['name'],
            'ref' => (string) $row['source_ref'],
            'attrs' => json_decode((string) $row['attributes'], true, flags: \JSON_THROW_ON_ERROR),
        ];
    }

    public function testASideIsStoredFootFirstWithItsOwnStableRef(): void
    {
        $this->seedRegion();
        $tester = $this->tester();
        $tester->execute(['artifact' => $this->artifact([self::row()])]);
        $tester->assertCommandIsSuccessful();

        $item = $this->stored('Test Pass from Testville');
        self::assertIsArray($item);
        // The ref carries the SIDE, not just the pass: one Q-id holds more than
        // one climb, and a ref that ignored that would upsert them onto each
        // other.
        self::assertSame('wikidata:Q1:0', $item['ref']);
        // Reversed: the harvester walks DOWN from the col, a rider goes up, and
        // ClimbProfiler measures from the first point.
        self::assertSame([50.37, 5.13], $item['attrs']['route'][0]);
        self::assertSame([50.40, 5.10], $item['attrs']['route'][3]);
        self::assertSame('From Testville', $item['attrs']['approach']);
    }

    /** A col the harvester took from OpenStreetMap is filed under osm, ref and all. */
    public function testAnOpenStreetMapColKeepsItsOwnProvenance(): void
    {
        $this->seedRegion();
        $tester = $this->tester();
        $row = self::row();
        $row['qid'] = 'osm:node:4242';
        $row['name'] = 'Osm Pass';
        $tester->execute(['artifact' => $this->artifact([$row])]);
        $tester->assertCommandIsSuccessful();

        $item = $this->stored('Osm Pass from Testville');
        self::assertIsArray($item);
        self::assertSame('osm:node:4242:0', $item['ref']);
        $source = static::getContainer()->get(Connection::class)->fetchOne('SELECT source FROM item WHERE source_ref = :r', ['r' => 'osm:node:4242:0']);
        self::assertSame('osm', (string) $source);
    }

    /** DROP rows are hiking trails and a via ferrata. There is no way in. */
    public function testDropRowsCannotBeSeededEvenOnRequest(): void
    {
        $this->seedRegion();
        $tester = $this->tester();
        $tester->execute([
            'artifact' => $this->artifact([self::row(['verdict' => 'DROP', 'name' => 'Via Ferrata'])]),
            '--verdict' => ['DROP'],
        ]);
        self::assertSame(2, $tester->getStatusCode(), 'INVALID, not a quiet skip');
        self::assertNull($this->stored('Via Ferrata from Testville'));

        // And it is not seeded by the default pass either.
        $tester = $this->tester();
        $tester->execute(['artifact' => $this->artifact([self::row(['verdict' => 'DROP', 'name' => 'Via Ferrata'])])]);
        $tester->assertCommandIsSuccessful();
        self::assertNull($this->stored('Via Ferrata from Testville'));
    }

    /**
     * Two sides whose feet geocoded to nothing usable would both be called
     * "Test Pass", and the second would upsert over the first - one climb
     * silently lost. They are told apart by a FACT (their length here, since
     * both run on the same road), never merged.
     */
    public function testTwoNamelessSidesOfOneColDoNotCollapseIntoOne(): void
    {
        $this->seedRegion();
        $tester = $this->tester();
        $tester->execute(['artifact' => $this->artifact([
            self::row(['side_index' => 0, 'foot_place' => null, 'length_m' => 8000]),
            self::row(['side_index' => 1, 'foot_place' => null, 'length_m' => 4000, 'line' => self::line(0.01)]),
        ])]);
        $tester->assertCommandIsSuccessful();

        self::assertIsArray($this->stored('Test Pass (8.0 km)'));
        self::assertIsArray($this->stored('Test Pass (4.0 km)'));
    }

    /** An administrative area is not how anyone says where a climb starts. */
    public function testAnAdministrativeAreaIsNotUsedAsAPlaceName(): void
    {
        $this->seedRegion();
        $tester = $this->tester();
        $tester->execute(['artifact' => $this->artifact([
            self::row(['foot_place' => 'Pitkin County']),
        ])]);
        $tester->assertCommandIsSuccessful();

        self::assertIsArray($this->stored('Test Pass'), 'the county is dropped, not shortened');
        self::assertNull($this->stored('Test Pass from Pitkin County'));
    }

    /** A foot named after the pass itself teaches the reader nothing twice. */
    public function testAPlaceAlreadyInTheNameIsNotRepeated(): void
    {
        $this->seedRegion();
        $tester = $this->tester();
        $tester->execute(['artifact' => $this->artifact([
            self::row(['name' => "Sir Lowry's Pass", 'foot_place' => "Sir Lowry's Pass"]),
        ])]);
        $tester->assertCommandIsSuccessful();
        self::assertIsArray($this->stored("Sir Lowry's Pass"));
    }

    /**
     * A row the trace found unpaved is refused rather than written claiming
     * asphalt: a wrong attribute on a rider-facing card is worse than a
     * missing climb.
     */
    public function testAnUnpavedLineIsRefusedRatherThanSeededAsAsphalt(): void
    {
        $this->seedRegion();
        $tester = $this->tester();
        $tester->execute(['artifact' => $this->artifact([
            self::row(['name' => 'Gravel Col', 'trace' => ['unpaved_pct' => 62.0, 'road' => '']]),
        ])]);
        $tester->assertCommandIsSuccessful();
        self::assertNull($this->stored('Gravel Col from Testville'));
        self::assertStringContainsString('unpaved', $tester->getDisplay());
    }

    /** A climb outside every onboarded region is invisible; never write one. */
    public function testAClimbOutsideEveryRegionIsSkippedAndNamed(): void
    {
        $this->seedRegion();
        $tester = $this->tester();
        $tester->execute(['artifact' => $this->artifact([
            self::row(['name' => 'Nowhere Pass', 'line' => [[10.0, 100.0], [10.1, 100.1]]]),
        ])]);
        $tester->assertCommandIsSuccessful();
        self::assertNull($this->stored('Nowhere Pass from Testville'));
        self::assertStringContainsString('Nowhere Pass', $tester->getDisplay());
    }

    /** The dry run reports without writing, like every other seeder here. */
    public function testDryRunWritesNothing(): void
    {
        $this->seedRegion();
        $tester = $this->tester();
        $tester->execute(['artifact' => $this->artifact([self::row()]), '--dry-run' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertNull($this->stored('Test Pass from Testville'));
        self::assertStringContainsString('Would seed', $tester->getDisplay());
    }
}
