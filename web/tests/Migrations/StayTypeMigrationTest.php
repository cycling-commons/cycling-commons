<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Tests\Coverage\CoverageSchema;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use DoctrineMigrations\Version20261008090000;
use DoctrineMigrations\Version20261008100000;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * O · Where to sleep gets a Type (Version20261008090000), and the merged
 * stay types take their OSM type (Version20261008100000),
 * docs/specs/osm-data-architecture.md §5a.
 */
final class StayTypeMigrationTest extends KernelTestCase
{
    use CoverageSchema;
    use MigrationHarness;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__, 2).'/migrations/Version20261008090000.php';
        require_once \dirname(__DIR__, 2).'/migrations/Version20261008100000.php';
    }

    private function db(): Connection
    {
        self::bootKernel();

        return static::getContainer()->get(Connection::class);
    }

    public function testTheImportLabelGivesTheTypeWithoutThePipelineTable(): void
    {
        $db = $this->db();
        $bnb = self::seedItem($db, 'O', ['t' => 'B&B']);
        $empty = self::seedItem($db, 'O', []);
        $unknown = self::seedItem($db, 'O', ['t' => 'Somewhere']);
        $typed = self::seedItem($db, 'O', ['type' => 'hostel', 't' => 'Hotel']);

        self::runUp($db, Version20261008090000::class);

        self::assertSame('guest_house', self::attributesOf($db, $bnb)['type'] ?? null);
        self::assertSame([], self::attributesOf($db, $empty), 'no answer: no Type');
        self::assertSame(['t' => 'Somewhere'], self::attributesOf($db, $unknown));
        self::assertSame('hostel', self::attributesOf($db, $typed)['type'] ?? null, 'a Type is its own answer');
    }

    public function testTheLinkedOsmPointsTourismTagWins(): void
    {
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['ref' => 'node/8850001', 'letter' => 'O', 'tags' => ['tourism' => 'hotel']]);
        $hotel = self::seedItem($db, 'O', [], osmRef: 'node/8850001');

        self::runUp($db, Version20261008090000::class);

        self::assertSame(['type' => 'hotel'], self::attributesOf($db, $hotel), 'an empty set becomes an object');
    }

    public function testASecondRunChangesNothing(): void
    {
        $db = $this->db();
        $ids = [self::seedItem($db, 'O', ['t' => 'Gîte']), self::seedItem($db, 'O', ['type' => 'bnb']), self::seedItem($db, 'O', [])];

        self::runUp($db, Version20261008090000::class);
        self::runUp($db, Version20261008100000::class);
        $first = array_map(static fn (int $id): array => self::attributesOf($db, $id), $ids);
        self::runUp($db, Version20261008090000::class);
        self::runUp($db, Version20261008100000::class);

        self::assertSame($first, array_map(static fn (int $id): array => self::attributesOf($db, $id), $ids));
        self::assertSame(['chalet', 'guest_house', null], array_map(static fn (array $a): mixed => $a['type'] ?? null, $first));
    }

    public function testTheMergedTypesTakeTheirOsmType(): void
    {
        $db = $this->db();
        $ids = ['bnb' => self::seedItem($db, 'O', ['type' => 'bnb']), 'gite' => self::seedItem($db, 'O', ['type' => 'gite']), 'budget' => self::seedItem($db, 'O', ['type' => 'budget'])];

        self::runUp($db, Version20261008100000::class);

        self::assertSame(
            ['bnb' => 'guest_house', 'gite' => 'chalet', 'budget' => 'hostel'],
            array_map(static fn (int $id): mixed => self::attributesOf($db, $id)['type'] ?? null, $ids),
        );
    }

    public function testTheTypeReadingDoesNotGoDown(): void
    {
        $this->expectException(IrreversibleMigration::class);
        self::runDown($this->db(), Version20261008090000::class);
    }

    public function testTheMergeDoesNotGoDown(): void
    {
        $this->expectException(IrreversibleMigration::class);
        self::runDown($this->db(), Version20261008100000::class);
    }
}
