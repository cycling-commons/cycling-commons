<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * G · Shelter gets a Type, one OSM shelter_type each (docs/specs/osm-data-architecture.md §5a),
 * in place of the free `shelterType` list.
 *
 * Per place, the first answer wins: the linked OSM point's `shelter_type` (or
 * `emergency=defibrillator`), when the pipeline's table exists; then the old
 * `shelterType`; then the import's `t` label. `shelterType` is removed. A place
 * with no answer keeps no Type: Refuge / chapel and Café (seasonal) name no
 * OSM shelter type, so a curator decides. Pending submissions get the same
 * reading of their proposed `shelterType`.
 *
 * The tables are a frozen copy of App\Catalog\PlaceKind on this date.
 */
final class Version20261008110000 extends AbstractMigration
{
    /** OSM `shelter_type` value → type. */
    private const array SHELTER_TYPE = [
        'basic_hut' => 'basic_hut', 'dugout' => 'dugout', 'field_shelter' => 'field_shelter', 'gazebo' => 'gazebo',
        'lean_to' => 'lean_to', 'pavilion' => 'pavilion', 'picnic_shelter' => 'picnic_shelter',
        'rock_shelter' => 'rock_shelter', 'sun_shelter' => 'sun_shelter', 'weather_shelter' => 'weather_shelter',
        'wildlife_hide' => 'wildlife_hide', 'public_transport' => 'bus_shelter',
    ];

    /** An old `shelterType` or `t` label → type. */
    private const array LABELS = [
        'Picnic hut' => 'picnic_shelter', 'Bus shelter' => 'bus_shelter', 'Defibrillator' => 'defibrillator',
        'Basic hut' => 'basic_hut', 'Dugout' => 'dugout', 'Field shelter' => 'field_shelter', 'Gazebo' => 'gazebo',
        'Lean-to' => 'lean_to', 'Pavilion' => 'pavilion', 'Picnic shelter' => 'picnic_shelter',
        'Rock shelter' => 'rock_shelter', 'Sun shelter' => 'sun_shelter', 'Weather shelter' => 'weather_shelter',
        'Wildlife hide' => 'wildlife_hide',
    ];

    #[\Override]
    public function getDescription(): string
    {
        return 'G shelters get a Type from OSM shelter_type; shelterType is removed';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $withOsm = null !== $this->connection->fetchOne("SELECT to_regclass('public.coverage_poi')");
        $osm = $withOsm
            ? 'LEFT JOIN coverage_poi cp ON cp.letter = i.letter AND cp.ref = COALESCE(i.osm_ref, CASE WHEN i.source_ref ~ \'^(node|way|relation)/\' THEN i.source_ref END)'
            : '';
        $tags = $withOsm ? "cp.tags->>'shelter_type' AS shelter_type, cp.tags->>'emergency' AS emergency" : 'NULL AS shelter_type, NULL AS emergency';
        $rows = $this->connection->fetchAllAssociative(
            "SELECT i.id, i.attributes->>'shelterType' AS old, i.attributes->>'t' AS t, {$tags}
               FROM item i {$osm}
              WHERE i.letter = 'G' AND i.attributes->'type' IS NULL"
        );
        foreach ($rows as $row) {
            $kind = self::SHELTER_TYPE[(string) $row['shelter_type']]
                ?? ('defibrillator' === $row['emergency'] ? 'defibrillator' : null)
                ?? self::LABELS[(string) $row['old']] ?? self::LABELS[(string) $row['t']] ?? null;
            // An empty attribute set can be stored as `[]`: a path is only set inside an object.
            $base = "CASE WHEN jsonb_typeof(attributes) = 'object' THEN attributes ELSE '{}'::jsonb END - 'shelterType'";
            $this->addSql(
                null === $kind
                    ? "UPDATE item SET attributes = {$base} WHERE id = :id"
                    : "UPDATE item SET attributes = jsonb_set({$base}, '{type}', to_jsonb(CAST(:kind AS text))) WHERE id = :id",
                null === $kind ? ['id' => $row['id']] : ['id' => $row['id'], 'kind' => $kind],
            );
        }

        $pending = $this->connection->fetchAllAssociative(
            "SELECT id, payload->'details'->>'shelterType' AS old FROM submission
              WHERE letter = 'G' AND status = 'pending' AND payload->'details'->'shelterType' IS NOT NULL"
        );
        foreach ($pending as $row) {
            $kind = self::LABELS[(string) $row['old']] ?? null;
            $this->addSql(
                null === $kind
                    ? "UPDATE submission SET payload = payload #- '{details,shelterType}', changes = changes - 'shelterType' WHERE id = :id"
                    : "UPDATE submission SET payload = jsonb_set(payload #- '{details,shelterType}', '{details,type}', to_jsonb(CAST(:kind AS text))),
                              changes = changes - 'shelterType' WHERE id = :id",
                null === $kind ? ['id' => $row['id']] : ['id' => $row['id'], 'kind' => $kind],
            );
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE item SET attributes = attributes - 'type' WHERE letter = 'G'");
    }
}
