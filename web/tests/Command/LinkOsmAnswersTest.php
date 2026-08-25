<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Command;

use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Tests\Coverage\CoverageSchema;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:catalog:link-osm` writes ANSWERS, not just refs
 * (catalog-data-model.md §5b).
 *
 * The stamp is what makes the rest of the machinery stop asking: without it
 * the approval gate would block a row the machine already settled, and the
 * default sweep would reconsider a "not in OSM" a curator recorded on purpose.
 */
final class LinkOsmAnswersTest extends KernelTestCase
{
    use CoverageSchema;

    private Connection $db;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        self::ensureCoverageSchema($this->db);
        $this->db->executeStatement("DELETE FROM item WHERE source_ref LIKE 'test:loa:%'");
        // One OSM viewpoint the linker can find.
        $this->db->executeStatement(
            "INSERT INTO coverage_poi (ref, letter, name, geom, tags, country_code)
             VALUES ('node/77001', 'P', 'Panorama Testberg', ST_SetSRID(ST_MakePoint(4.9, 50.5), 4326), '{}', 'BE')",
        );
    }

    public function testAConfidentLinkIsRecordedAsAnAnswer(): void
    {
        // Same name key, ~11 m away: inside OsmLinker::TIGHT_M.
        $id = $this->seed('Panorama Testberg', 50.5001, 4.9);

        $this->runCommand(['--write' => true]);

        $row = $this->row($id);
        self::assertSame('node/77001', $row['osm_ref']);
        self::assertNotNull($row['osm_checked_at'], 'the linker answered it, so nothing should ask again');
    }

    public function testARowInTheReviewBandIsLeftUnanswered(): void
    {
        // Same name key but ~120 m away: the 50-250 m band is a human call.
        $id = $this->seed('Panorama Testberg', 50.5011, 4.9);

        $this->runCommand(['--write' => true]);

        $row = $this->row($id);
        self::assertNull($row['osm_ref']);
        self::assertNull($row['osm_checked_at'],
            'pretending that band is answered would hide it from the data desk');
    }

    public function testACuratorsNotInOsmIsNeverReconsidered(): void
    {
        $id = $this->seed('Panorama Testberg', 50.5001, 4.9);
        $this->db->executeStatement(
            'UPDATE item SET osm_checked_at = NOW() WHERE id = :id', ['id' => $id],
        );

        $this->runCommand(['--write' => true]);

        $row = $this->row($id);
        self::assertNull($row['osm_ref'],
            'a human said "not in OSM"; the sweep must not overrule that by default');
    }

    private function seed(string $name, float $lat, float $lng): int
    {
        $this->db->executeStatement(
            'INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at)
             VALUES (\'P\', :name, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326), \'BE\', :state, :source, :ref, \'{}\', NOW(), NOW())',
            ['name' => $name, 'lat' => $lat, 'lng' => $lng,
                'state' => ItemState::Unverified->value, 'source' => ItemSource::User->value,
                'ref' => 'test:loa:'.uniqid('', true)],
        );

        return (int) $this->db->fetchOne('SELECT max(id) FROM item WHERE source_ref LIKE :p', ['p' => 'test:loa:%']);
    }

    /** @return array{osm_ref: ?string, osm_checked_at: ?string} */
    private function row(int $id): array
    {
        /** @var array{osm_ref: ?string, osm_checked_at: ?string} $row */
        $row = $this->db->fetchAssociative('SELECT osm_ref, osm_checked_at FROM item WHERE id = :id', ['id' => $id]);

        return $row;
    }

    /** @param array<string, mixed> $args */
    private function runCommand(array $args = []): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:catalog:link-osm'));
        $tester->execute($args);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }
}
