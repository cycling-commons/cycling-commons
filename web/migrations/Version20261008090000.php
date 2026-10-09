<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * O · Where to sleep gets a Type (docs/specs/osm-data-architecture.md §5a):
 * one type per OSM `tourism` tag. A B&B is a guest house, a gîte a chalet, a
 * budget stay a hostel; the labels name both words.
 *
 * Per place, the first answer wins: the linked OSM point's `tourism` tag
 * (`coverage_poi`, joined on `osm_ref`, else an OSM `source_ref`, when the
 * pipeline's table exists), then the import's `t` label. A place with
 * neither keeps no Type. Only a place with no Type is read, so a second run
 * changes nothing. Submissions need no reading: O had no Type to propose.
 *
 * The tables are a frozen copy of App\Catalog\PlaceKind on this date.
 */
final class Version20261008090000 extends AbstractMigration
{
    /** OSM `tourism` value → stay type. */
    private const array TOURISM = [
        'hotel' => 'hotel', 'motel' => 'motel', 'guest_house' => 'guest_house', 'apartment' => 'apartment',
        'hostel' => 'hostel', 'camp_site' => 'camp', 'chalet' => 'chalet',
        'alpine_hut' => 'alpine_hut', 'wilderness_hut' => 'wilderness_hut',
    ];

    /** English `t` label → stay type. */
    private const array LABELS = [
        'Hotel' => 'hotel', 'Motel' => 'motel', 'B&B' => 'guest_house', 'Guest house' => 'guest_house', 'Gîte' => 'chalet',
        'Holiday rental' => 'apartment', 'Hostel' => 'hostel', 'Budget stay' => 'hostel', 'Campsite' => 'camp',
        'Chalet' => 'chalet', 'Mountain hut' => 'alpine_hut', 'Wilderness hut' => 'wilderness_hut',
        'Furnished rental' => 'apartment', 'Gîte / guesthouse' => 'guest_house',
    ];

    #[\Override]
    public function getDescription(): string
    {
        return 'O places to sleep get a Type: the OSM tourism tag or the import label';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $withOsm = null !== $this->connection->fetchOne("SELECT to_regclass('public.coverage_poi')");
        $osm = $withOsm
            ? 'LEFT JOIN coverage_poi cp ON cp.letter = i.letter AND cp.ref = COALESCE(i.osm_ref, CASE WHEN i.source_ref ~ \'^(node|way|relation)/\' THEN i.source_ref END)'
            : '';
        $tourism = $withOsm ? "cp.tags->>'tourism'" : 'NULL';
        $rows = $this->connection->fetchAllAssociative(
            "SELECT i.id, i.attributes->>'t' AS t, {$tourism} AS tourism
               FROM item i {$osm}
              WHERE i.letter = 'O' AND i.attributes->'type' IS NULL"
        );
        foreach ($rows as $row) {
            $kind = self::TOURISM[(string) $row['tourism']] ?? self::LABELS[(string) $row['t']] ?? null;
            if (null === $kind) {
                continue;
            }
            // An empty attribute set can be stored as `[]`: a path is only set inside an object.
            $this->addSql(
                "UPDATE item SET attributes = jsonb_set(CASE WHEN jsonb_typeof(attributes) = 'object' THEN attributes ELSE '{}'::jsonb END, '{type}', to_jsonb(CAST(:kind AS text))) WHERE id = :id",
                ['id' => $row['id'], 'kind' => $kind],
            );
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'O Types cannot be removed again: a curator may have set or changed one since, and nothing tells those apart from this reading. Restore a backup taken before this migration.'
        );
    }
}
