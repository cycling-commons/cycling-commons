<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Tests\Coverage\CoverageSchema;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use DoctrineMigrations\Version20261008110000;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * G · Shelter gets a Type in place of `shelterType` (Version20261008110000,
 * docs/specs/osm-data-architecture.md §5a).
 */
final class ShelterTypeMigrationTest extends KernelTestCase
{
    use CoverageSchema;
    use MigrationHarness;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__, 2).'/migrations/Version20261008110000.php';
    }

    private function db(): Connection
    {
        self::bootKernel();

        return static::getContainer()->get(Connection::class);
    }

    public function testTheOldShelterTypeOrLabelGivesTheTypeWithoutThePipelineTable(): void
    {
        $db = $this->db();
        $picnic = self::seedItem($db, 'G', ['shelterType' => 'Picnic hut', 'note' => 'kept']);
        $bus = self::seedItem($db, 'G', ['t' => 'Bus shelter']);
        $chapel = self::seedItem($db, 'G', ['shelterType' => 'Refuge / chapel']);
        $empty = self::seedItem($db, 'G', []);
        $typed = self::seedItem($db, 'G', ['type' => 'gazebo', 'shelterType' => 'Picnic hut']);

        self::runUp($db, Version20261008110000::class);

        self::assertSame(['note' => 'kept', 'type' => 'picnic_shelter'], self::sorted(self::attributesOf($db, $picnic)));
        self::assertSame('bus_shelter', self::attributesOf($db, $bus)['type'] ?? null);
        self::assertSame([], self::attributesOf($db, $chapel), 'no OSM shelter type: a curator decides');
        self::assertSame([], self::attributesOf($db, $empty));
        self::assertSame(['type' => 'gazebo'], self::attributesOf($db, $typed), 'a Type is its own answer, and shelterType goes');
    }

    public function testTheLinkedOsmPointsShelterTypeWins(): void
    {
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['ref' => 'node/8860001', 'letter' => 'G', 'tags' => ['amenity' => 'shelter', 'shelter_type' => 'lean_to']]);
        $leanTo = self::seedItem($db, 'G', ['shelterType' => 'Picnic hut'], osmRef: 'node/8860001');

        self::runUp($db, Version20261008110000::class);

        self::assertSame(['type' => 'lean_to'], self::attributesOf($db, $leanTo));
    }

    public function testEverySubmissionThatCanStillBeApprovedProposesTheType(): void
    {
        $db = $this->db();
        $payload = ['details' => ['name' => 'Hut', 'shelterType' => 'Bus shelter']];
        $changes = ['shelterType' => ['was' => 'Picnic hut', 'now' => 'Bus shelter']];
        $pending = self::seedSubmission($db, 'G', 'pending', $payload, $changes);
        $needsInfo = self::seedSubmission($db, 'G', 'needs_info', $payload, $changes);
        $trashed = self::seedSubmission($db, 'G', 'trashed', $payload, $changes, trashedFrom: 'pending');
        $decided = self::seedSubmission($db, 'G', 'approved', $payload, $changes);
        $unknown = self::seedSubmission($db, 'G', 'pending', ['details' => ['shelterType' => 'Café (seasonal)']], ['shelterType' => ['was' => null, 'now' => 'Café (seasonal)']]);

        self::runUp($db, Version20261008110000::class);

        foreach (['pending' => $pending, 'needs_info' => $needsInfo, 'trashed from pending' => $trashed] as $what => $id) {
            $row = self::submissionOf($db, $id);
            self::assertSame('bus_shelter', $row['payload']['details']['type'] ?? null, $what);
            self::assertArrayNotHasKey('shelterType', $row['payload']['details'], $what);
            self::assertArrayNotHasKey('shelterType', $row['changes'], $what);
            // ModerationService::applyEdit() applies `changes`: the Type has to be there.
            self::assertEquals(['was' => 'picnic_shelter', 'now' => 'bus_shelter'], $row['changes']['type'] ?? null, $what);
        }
        self::assertEquals($changes, self::submissionOf($db, $decided)['changes'], 'a decided submission keeps what was said then');
        $unknownRow = self::submissionOf($db, $unknown);
        self::assertSame([], $unknownRow['payload']['details']);
        self::assertSame([], $unknownRow['changes']);
    }

    public function testASecondRunChangesNothing(): void
    {
        $db = $this->db();
        $item = self::seedItem($db, 'G', ['shelterType' => 'Picnic hut']);
        $sub = self::seedSubmission($db, 'G', 'needs_info', ['details' => ['shelterType' => 'Gazebo']], ['shelterType' => ['was' => null, 'now' => 'Gazebo']]);

        self::runUp($db, Version20261008110000::class);
        $first = [self::attributesOf($db, $item), self::submissionOf($db, $sub)];
        self::runUp($db, Version20261008110000::class);

        self::assertSame($first, [self::attributesOf($db, $item), self::submissionOf($db, $sub)]);
        self::assertSame(['type' => 'picnic_shelter'], $first[0]);
    }

    public function testDownRefusesRatherThanDropEveryType(): void
    {
        $this->expectException(IrreversibleMigration::class);
        self::runDown($this->db(), Version20261008110000::class);
    }

    /**
     * @param array<string, mixed>|list<mixed> $a
     *
     * @return array<string, mixed>|list<mixed>
     */
    private static function sorted(array $a): array
    {
        ksort($a);

        return $a;
    }
}
