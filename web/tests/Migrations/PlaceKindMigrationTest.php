<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Tests\Coverage\CoverageSchema;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use DoctrineMigrations\Version20261007140000;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * P and Q Types become kinds (Version20261007140000,
 * docs/specs/osm-data-architecture.md §5a).
 */
final class PlaceKindMigrationTest extends KernelTestCase
{
    use CoverageSchema;
    use MigrationHarness;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__, 2).'/migrations/Version20261007140000.php';
    }

    private function db(): Connection
    {
        self::bootKernel();

        return static::getContainer()->get(Connection::class);
    }

    public function testOldLabelsBecomeKindsAndBroadLabelsAreCleared(): void
    {
        $db = $this->db();
        $viewpoint = self::seedItem($db, 'P', ['type' => 'Viewpoint']);
        $castleT = self::seedItem($db, 'Q', ['t' => 'Castle']);
        $broad = self::seedItem($db, 'P', ['type' => 'Viewpoint / high point', 'note' => 'kept']);
        $scout = self::seedItem($db, 'P', ['type' => 'Viewpoint / high point'], source: 'scout');
        $empty = self::seedItem($db, 'Q', []);
        $untyped = self::seedItem($db, 'Q', ['note' => 'no type']);

        self::runUp($db, Version20261007140000::class);

        self::assertSame('viewpoint', self::attributesOf($db, $viewpoint)['type'] ?? null);
        self::assertSame('castle', self::attributesOf($db, $castleT)['type'] ?? null);
        self::assertSame(['note' => 'kept'], self::attributesOf($db, $broad), 'a viewpoint or a peak: a curator decides');
        self::assertSame('viewpoint', self::attributesOf($db, $scout)['type'] ?? null, 'only the device VIEW wrote it');
        self::assertSame([], self::attributesOf($db, $empty), 'an empty attribute set stays as it was');
        self::assertSame(['note' => 'no type'], self::attributesOf($db, $untyped));
    }

    public function testAValidKindIsItsOwnAnswer(): void
    {
        $db = $this->db();
        $ids = [];
        foreach (['P' => ['nature', 'peak', 'viewpoint'], 'Q' => ['castle', 'heritage', 'museum', 'worship', 'architecture']] as $letter => $kinds) {
            foreach ($kinds as $kind) {
                $ids[$kind] = self::seedItem($db, $letter, ['type' => $kind]);
            }
        }

        self::runUp($db, Version20261007140000::class);

        foreach ($ids as $kind => $id) {
            self::assertSame($kind, self::attributesOf($db, $id)['type'] ?? null, $kind.' is kept');
        }
    }

    public function testAWikidataHeritageSiteIsACastleWhateverItsName(): void
    {
        // Q23413 (castle) was the only Wikidata class the harvest filed as Heritage site.
        $db = $this->db();
        $eltz = self::seedItem($db, 'Q', ['type' => 'Heritage site'], 'Burg Eltz', 'wikidata');
        $own = self::seedItem($db, 'Q', ['type' => 'Heritage site'], 'Castle Hill Park', 'user');
        $chosen = self::seedItem($db, 'Q', ['type' => 'Heritage site'], 'Abbaye test', 'wikidata');
        $db->executeStatement(
            "INSERT INTO change_history (item_id, field, old_value, new_value, changed_by, changed_at)
             VALUES (:id, 'type', '\"Monument\"', '\"Heritage site\"', 4242, NOW())",
            ['id' => $chosen],
        );

        self::runUp($db, Version20261007140000::class);

        self::assertSame('castle', self::attributesOf($db, $eltz)['type'] ?? null);
        self::assertSame('heritage', self::attributesOf($db, $own)['type'] ?? null, 'the name says nothing on a row the harvest did not write');
        self::assertSame('heritage', self::attributesOf($db, $chosen)['type'] ?? null, 'a person chose Heritage site');
    }

    public function testTheLinkedOsmPointsTagWinsAndAHarvestedKindBeatsAPeak(): void
    {
        $db = $this->db();
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['ref' => 'node/8840001', 'letter' => 'P', 'tags' => ['natural' => 'peak', 'waterway' => 'waterfall']]);
        self::insertCoveragePoi($db, ['ref' => 'node/8840002', 'letter' => 'Q', 'tags' => ['historic' => 'ruins']]);
        $fall = self::seedItem($db, 'P', ['type' => 'Natural feature'], osmRef: 'node/8840001');
        $ruins = self::seedItem($db, 'Q', [], source: 'osm', sourceRef: 'node/8840002');

        self::runUp($db, Version20261007140000::class);

        self::assertSame('waterfall', self::attributesOf($db, $fall)['type'] ?? null, 'the pipeline knows harvested kinds only');
        self::assertSame(['type' => 'ruins'], self::attributesOf($db, $ruins), 'an empty set becomes an object');
    }

    public function testEverySubmissionThatCanStillBeApprovedIsRead(): void
    {
        $db = $this->db();
        $details = static fn (string $type): array => ['details' => ['type' => $type, 'name' => 'X']];
        $changes = static fn (string $type): array => ['type' => ['was' => null, 'now' => $type]];
        $pending = self::seedSubmission($db, 'Q', 'pending', $details('Museum / culture'), $changes('Museum / culture'));
        $needsInfo = self::seedSubmission($db, 'Q', 'needs_info', $details('Museum / culture'), $changes('Museum / culture'));
        $trashed = self::seedSubmission($db, 'Q', 'trashed', $details('Museum / culture'), $changes('Museum / culture'), trashedFrom: 'needs_info');
        $decided = self::seedSubmission($db, 'Q', 'approved', $details('Museum / culture'), $changes('Museum / culture'));
        $trashedDecided = self::seedSubmission($db, 'Q', 'trashed', $details('Museum / culture'), $changes('Museum / culture'), trashedFrom: 'rejected');
        $kind = self::seedSubmission($db, 'Q', 'pending', $details('castle'), $changes('castle'));
        $broad = self::seedSubmission($db, 'Q', 'pending', $details('Religious site'), $changes('Religious site'));

        self::runUp($db, Version20261007140000::class);

        foreach (['pending' => $pending, 'needs_info' => $needsInfo, 'trashed from needs_info' => $trashed] as $what => $id) {
            $row = self::submissionOf($db, $id);
            self::assertSame('museum', $row['payload']['details']['type'] ?? null, $what);
            self::assertEquals(['was' => null, 'now' => 'museum'], $row['changes']['type'] ?? null, $what.': an approval applies the kind');
        }
        foreach (['approved' => $decided, 'trashed from rejected' => $trashedDecided] as $what => $id) {
            self::assertSame('Museum / culture', self::submissionOf($db, $id)['payload']['details']['type'] ?? null, $what.' keeps what was said then');
        }
        self::assertSame('castle', self::submissionOf($db, $kind)['changes']['type']['now'] ?? null);
        $broadRow = self::submissionOf($db, $broad);
        self::assertArrayNotHasKey('type', $broadRow['payload']['details']);
        self::assertArrayNotHasKey('type', $broadRow['changes']);
    }

    public function testASecondRunChangesNothing(): void
    {
        $db = $this->db();
        $items = [
            self::seedItem($db, 'Q', ['type' => 'Heritage site'], 'Burg Eltz', 'wikidata'),
            self::seedItem($db, 'Q', ['type' => 'Heritage site'], 'Castle Hill Park'),
            self::seedItem($db, 'P', ['type' => 'Viewpoint / high point'], source: 'scout'),
            self::seedItem($db, 'Q', ['type' => 'Museum / culture']),
        ];
        $sub = self::seedSubmission($db, 'P', 'needs_info', ['via' => 'scout', 'details' => ['type' => 'Viewpoint / high point']], ['type' => ['was' => null, 'now' => 'Viewpoint / high point']]);

        self::runUp($db, Version20261007140000::class);
        $first = array_map(static fn (int $id): array => self::attributesOf($db, $id), $items);
        $firstSub = self::submissionOf($db, $sub);
        self::runUp($db, Version20261007140000::class);

        self::assertSame($first, array_map(static fn (int $id): array => self::attributesOf($db, $id), $items));
        self::assertSame($firstSub, self::submissionOf($db, $sub));
        self::assertSame(['castle', 'heritage', 'viewpoint', 'museum'], array_map(static fn (array $a): mixed => $a['type'] ?? null, $first));
        self::assertSame('viewpoint', $firstSub['payload']['details']['type'] ?? null);
    }

    public function testDownRefusesRatherThanGuess(): void
    {
        $this->expectException(IrreversibleMigration::class);
        self::runDown($this->db(), Version20261007140000::class);
    }
}
