<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Command;

use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:catalog:dedupe` — the curator half of "one place, one row".
 *
 * `DuplicateGuard` stops new duplicates at import and never writes, because
 * retiring a row is a curator decision and never automatic
 * (catalog-data-model.md §4). This command is that decision: a human reads the
 * dry run, then passes `--write`.
 *
 * The two rules worth breaking a build over are here — a losing row is
 * RETIRED and not deleted, and a row carrying curator edits is never touched
 * at all.
 */
final class DedupePlacesCommandTest extends KernelTestCase
{
    private Connection $db;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->db->executeStatement("DELETE FROM change_history WHERE item_id IN (SELECT id FROM item WHERE source_ref LIKE 'test:dd%')");
        $this->db->executeStatement("DELETE FROM item WHERE source_ref LIKE 'test:dd%'");
    }

    public function testTheDryRunChangesNothing(): void
    {
        $osm = $this->seed('E', 'Hôtel Koru', 50.66887, 4.90664, ItemSource::Osm, 'a');
        $pivot = $this->seed('E', 'Hôtel Koru', 50.66884, 4.90664, ItemSource::Pivot, 'b');

        $tester = $this->run_();

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('would be retired', $tester->getDisplay());
        self::assertSame(ItemState::Unverified->value, $this->stateOf($osm));
        self::assertSame(ItemState::Unverified->value, $this->stateOf($pivot));
    }

    public function testWriteRetiresTheWeakerSourceAndKeepsTheBetterOne(): void
    {
        // The exact live case: one hotel, a PIVOT row and an OSM row, two pins
        // on the map. PIVOT is canonical for accommodation and carries its own
        // CC-BY attribution, so it is the one that stays.
        $osm = $this->seed('E', 'Hôtel Koru', 50.66887, 4.90664, ItemSource::Osm, 'a');
        $pivot = $this->seed('E', 'Hôtel Koru', 50.66884, 4.90664, ItemSource::Pivot, 'b');

        $this->run_(['--write' => true]);

        self::assertSame(ItemState::Retired->value, $this->stateOf($osm));
        self::assertSame(ItemState::Unverified->value, $this->stateOf($pivot));
    }

    public function testTheLoserIsRetiredNeverDeleted(): void
    {
        // Retire, never delete: the row's id may be referenced by
        // confirmations, history and moderation rows that must not dangle.
        $osm = $this->seed('E', 'Hôtel Koru', 50.66887, 4.90664, ItemSource::Osm, 'a');
        $this->seed('E', 'Hôtel Koru', 50.66884, 4.90664, ItemSource::Pivot, 'b');

        $this->run_(['--write' => true]);

        self::assertTrue(
            (bool) $this->db->fetchOne('SELECT 1 FROM item WHERE id = :id', ['id' => $osm]),
            'the losing row was deleted, not retired',
        );
    }

    public function testImportOrderDoesNotDecideTheWinner(): void
    {
        // Seeded the other way round: the OSM row is the OLDER one. Rank must
        // still decide, or whichever harvest happened to run first wins by
        // accident and the canonical row is the casualty.
        $pivot = $this->seed('E', 'Auberge du Test', 50.60000, 4.60000, ItemSource::Pivot, 'later');
        $osm = $this->seed('E', 'Auberge du Test', 50.60001, 4.60000, ItemSource::Osm, 'aaa-earlier');

        $this->run_(['--write' => true]);

        self::assertSame(ItemState::Retired->value, $this->stateOf($osm));
        self::assertSame(ItemState::Unverified->value, $this->stateOf($pivot));
    }

    public function testACuratorEditedRowIsNeverRetired(): void
    {
        // A human spent time on this row. The command cannot weigh that against
        // a source ranking, so it declines to choose and says so.
        $osm = $this->seed('E', 'Hôtel Koru', 50.66887, 4.90664, ItemSource::Osm, 'a');
        $pivot = $this->seed('E', 'Hôtel Koru', 50.66884, 4.90664, ItemSource::Pivot, 'b');
        $this->edit($osm);

        $tester = $this->run_(['--write' => true]);

        self::assertSame(ItemState::Unverified->value, $this->stateOf($osm));
        self::assertSame(ItemState::Unverified->value, $this->stateOf($pivot));
        self::assertStringContainsString('curator edits', $tester->getDisplay());
    }

    public function testThreeRowsForOnePlaceLeaveExactlyOne(): void
    {
        // Signal de Botrange, as found: two OSM rows and one Wikidata row,
        // all within 54 m.
        $osmA = $this->seed('I', 'Signal de Botrange', 50.50158, 6.09255, ItemSource::Osm, 'a');
        $osmB = $this->seed('I', 'Signal de Botrange', 50.50203, 6.09283, ItemSource::Osm, 'b');
        $wd = $this->seed('I', 'Signal de Botrange', 50.50167, 6.09306, ItemSource::Wikidata, 'c');

        $this->run_(['--write' => true]);

        self::assertSame(ItemState::Unverified->value, $this->stateOf($wd));
        self::assertSame(ItemState::Retired->value, $this->stateOf($osmA));
        self::assertSame(ItemState::Retired->value, $this->stateOf($osmB));
    }

    public function testANamesakeFarAwayIsLeftAlone(): void
    {
        $sydney = $this->seed('J', "St Mary's Cathedral", -33.87111, 151.21333, ItemSource::Wikidata, 'a');
        $tokyo = $this->seed('J', 'St. Mary\'s Cathedral', 35.71417, 139.72667, ItemSource::Wikidata, 'b');

        $tester = $this->run_(['--write' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(ItemState::Unverified->value, $this->stateOf($sydney));
        self::assertSame(ItemState::Unverified->value, $this->stateOf($tokyo));
    }

    public function testTheLetterFilterBoundsTheSweep(): void
    {
        $osm = $this->seed('O', 'Hôtel Koru', 50.66887, 4.90664, ItemSource::Osm, 'a');
        $this->seed('O', 'Hôtel Koru', 50.66884, 4.90664, ItemSource::Pivot, 'b');

        $this->run_(['--write' => true, '--letter' => 'P']);

        self::assertSame(ItemState::Unverified->value, $this->stateOf($osm));
    }

    public function testASecondRunIsAQuietNoOp(): void
    {
        $this->seed('O', 'Hôtel Koru', 50.66887, 4.90664, ItemSource::Osm, 'a');
        $this->seed('O', 'Hôtel Koru', 50.66884, 4.90664, ItemSource::Pivot, 'b');

        $this->run_(['--write' => true]);
        $second = $this->run_(['--write' => true]);

        // The retired row is out of the served set, so it stops being a
        // duplicate of anything.
        self::assertStringContainsString('No duplicate places', $second->getDisplay());
    }

    /** @param array<string, mixed> $args */
    private function run_(array $args = []): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:catalog:dedupe'));
        $tester->execute($args);

        return $tester;
    }

    private function seed(string $letter, string $name, float $lat, float $lng, ItemSource $source, string $ref): int
    {
        $this->db->executeStatement(
            'INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at)
             VALUES (:letter, :name, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326), :cc, :state, :source, :ref, :attrs, NOW(), NOW())',
            [
                'letter' => $letter, 'name' => $name, 'lat' => $lat, 'lng' => $lng,
                'cc' => 'BE', 'state' => ItemState::Unverified->value, 'source' => $source->value,
                'ref' => 'test:dd:'.$ref, 'attrs' => '{}',
            ],
        );

        return (int) $this->db->fetchOne('SELECT id FROM item WHERE source_ref = :ref', ['ref' => 'test:dd:'.$ref]);
    }

    /** A curator edit, as the shield sees it: any change_history row. */
    private function edit(int $itemId): void
    {
        $this->db->executeStatement(
            "INSERT INTO change_history (item_id, field, old_value, new_value, changed_by, changed_at)
             VALUES (:id, 'name', '\"before\"', '\"after\"', 1, NOW())",
            ['id' => $itemId],
        );
    }

    private function stateOf(int $id): string
    {
        return (string) $this->db->fetchOne('SELECT state FROM item WHERE id = :id', ['id' => $id]);
    }
}
