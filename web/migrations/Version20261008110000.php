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
 * Per place, the first answer wins: a Type it has already; the linked OSM
 * point's `shelter_type`, when the pipeline's table exists (a G point there
 * is one of the eleven harvested values); then the old `shelterType`; then
 * the import's `t` label. Bus shelter and Defibrillator come from those labels
 * only: the harvest stores neither tag. `shelterType` is removed. A place with
 * no answer keeps no Type: Refuge / chapel and Café (seasonal) name no OSM
 * shelter type, so a curator decides.
 *
 * Submissions a curator can still approve (pending, needs info, and those in
 * Trash from either) get the same reading of their proposed `shelterType`: in
 * the payload, and in `changes`, which an approval applies, as the Type
 * `{was, now}`. A second run changes nothing.
 *
 * The tables are a frozen copy of App\Catalog\PlaceKind on this date.
 */
final class Version20261008110000 extends AbstractMigration
{
    /** OSM `shelter_type` value → type: the harvested values. */
    private const array SHELTER_TYPE = [
        'basic_hut' => 'basic_hut', 'dugout' => 'dugout', 'field_shelter' => 'field_shelter', 'gazebo' => 'gazebo',
        'lean_to' => 'lean_to', 'pavilion' => 'pavilion', 'picnic_shelter' => 'picnic_shelter',
        'rock_shelter' => 'rock_shelter', 'sun_shelter' => 'sun_shelter', 'weather_shelter' => 'weather_shelter',
        'wildlife_hide' => 'wildlife_hide',
    ];

    /** An old `shelterType` or `t` label → type. */
    private const array LABELS = [
        'Picnic hut' => 'picnic_shelter', 'Bus shelter' => 'bus_shelter', 'Defibrillator' => 'defibrillator',
        'Basic hut' => 'basic_hut', 'Dugout' => 'dugout', 'Field shelter' => 'field_shelter', 'Gazebo' => 'gazebo',
        'Lean-to' => 'lean_to', 'Pavilion' => 'pavilion', 'Picnic shelter' => 'picnic_shelter',
        'Rock shelter' => 'rock_shelter', 'Sun shelter' => 'sun_shelter', 'Weather shelter' => 'weather_shelter',
        'Wildlife hide' => 'wildlife_hide',
    ];

    /** Submissions a curator can still approve, directly or once restored from Trash. */
    private const string OPEN_SUBMISSION_SQL = "(status IN ('pending', 'needs_info') OR (status = 'trashed' AND trashed_from IN ('pending', 'needs_info')))";

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
        $shelterType = $withOsm ? "cp.tags->>'shelter_type'" : 'NULL';
        $rows = $this->connection->fetchAllAssociative(
            "SELECT i.id, i.attributes->>'type' AS type, i.attributes->>'shelterType' AS old, i.attributes->>'t' AS t, {$shelterType} AS shelter_type
               FROM item i {$osm}
              WHERE i.letter = 'G' AND (i.attributes->'type' IS NULL OR i.attributes->'shelterType' IS NOT NULL)"
        );
        foreach ($rows as $row) {
            $kind = $this->ownType($row['type'])
                ?? self::SHELTER_TYPE[(string) $row['shelter_type']]
                ?? self::LABELS[(string) $row['old']] ?? self::LABELS[(string) $row['t']] ?? null;
            if (null === $kind && null === $row['old']) {
                continue;
            }
            // An empty attribute set can be stored as `[]`: a path is only set inside an object.
            $base = "CASE WHEN jsonb_typeof(attributes) = 'object' THEN attributes ELSE '{}'::jsonb END - 'shelterType'";
            $this->addSql(
                null === $kind
                    ? "UPDATE item SET attributes = {$base} WHERE id = :id"
                    : "UPDATE item SET attributes = jsonb_set({$base}, '{type}', to_jsonb(CAST(:kind AS text))) WHERE id = :id",
                null === $kind ? ['id' => $row['id']] : ['id' => $row['id'], 'kind' => $kind],
            );
        }

        $open = $this->connection->fetchAllAssociative(
            "SELECT id, COALESCE(payload->'details'->>'shelterType', changes->'shelterType'->>'now') AS old, changes->'shelterType'->>'was' AS was
               FROM submission
              WHERE letter = 'G' AND ".self::OPEN_SUBMISSION_SQL."
                AND (payload->'details'->'shelterType' IS NOT NULL OR changes->'shelterType' IS NOT NULL)"
        );
        foreach ($open as $row) {
            $kind = self::LABELS[(string) $row['old']] ?? null;
            $payload = null === $kind
                ? "payload #- '{details,shelterType}'"
                : "CASE WHEN payload->'details'->'shelterType' IS NOT NULL
                        THEN jsonb_set(payload #- '{details,shelterType}', '{details,type}', to_jsonb(CAST(:kind AS text)))
                        ELSE payload END";
            // ModerationService::applyEdit() applies `changes` only: the Type goes there as {was, now}.
            $changes = null === $kind
                ? "changes - 'shelterType'"
                : "CASE WHEN changes->'shelterType' IS NOT NULL
                        THEN jsonb_set(changes - 'shelterType', '{type}', jsonb_build_object('was', CAST(:was AS text), 'now', CAST(:kind AS text)))
                        ELSE changes END";
            $this->addSql(
                "UPDATE submission SET payload = {$payload}, changes = {$changes} WHERE id = :id",
                null === $kind ? ['id' => $row['id']] : ['id' => $row['id'], 'kind' => $kind, 'was' => self::LABELS[(string) $row['was']] ?? null],
            );
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'G Types cannot become shelterType again: several types share no old label, and the removed shelterType values are gone. Restore a backup taken before this migration.'
        );
    }

    private function ownType(mixed $type): ?string
    {
        return \is_string($type) && (\in_array($type, self::SHELTER_TYPE, true) || \in_array($type, self::LABELS, true)) ? $type : null;
    }
}
