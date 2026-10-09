<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;

/**
 * Runs one data migration against the test database inside the test's own
 * transaction (DAMA rolls it back), with rows seeded first. The classes in
 * `migrations/` are not autoloaded, so each test requires its file.
 */
trait MigrationHarness
{
    /**
     * @param class-string<AbstractMigration> $class
     *
     * @psalm-suppress InternalMethod a data migration reads no schema; Doctrine passes an empty one the same way
     */
    private static function runUp(Connection $db, string $class): void
    {
        $migration = new $class($db, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /**
     * @param class-string<AbstractMigration> $class
     *
     * @psalm-suppress InternalMethod a data migration reads no schema; Doctrine passes an empty one the same way
     */
    private static function runDown(Connection $db, string $class): void
    {
        (new $class($db, new NullLogger()))->down(new Schema());
    }

    /** @param array<string, mixed>|list<mixed> $attributes */
    private static function seedItem(Connection $db, string $letter, array $attributes, string $name = 'Test place', string $source = 'user', ?string $osmRef = null, ?string $sourceRef = null): int
    {
        return (int) $db->fetchOne(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, osm_ref, attributes, created_at, updated_at)
             VALUES (:letter, :name, ST_SetSRID(ST_MakePoint(5.1, 50.1), 4326), 'BE', 'unverified', :source, :ref, :osm, CAST(:attrs AS jsonb), NOW(), NOW())
             RETURNING id",
            [
                'letter' => $letter, 'name' => $name, 'source' => $source,
                'ref' => $sourceRef ?? 'test:mig:'.bin2hex(random_bytes(6)), 'osm' => $osmRef,
                'attrs' => json_encode($attributes, \JSON_THROW_ON_ERROR),
            ],
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $changes
     */
    private static function seedSubmission(Connection $db, string $letter, string $status, array $payload, array $changes = [], string $type = 'edit', ?int $itemId = null, ?string $trashedFrom = null): int
    {
        return (int) $db->fetchOne(
            "INSERT INTO submission (type, letter, item_id, user_id, status, title, geom, country_code, changes, payload, created_at, trashed_from)
             VALUES (:type, :letter, :item, 4242, :status, 'Test', ST_SetSRID(ST_MakePoint(5.1, 50.1), 4326), 'BE', CAST(:changes AS jsonb), CAST(:payload AS jsonb), NOW(), :from)
             RETURNING id",
            [
                'type' => $type, 'letter' => $letter, 'item' => $itemId, 'status' => $status,
                'changes' => json_encode((object) $changes, \JSON_THROW_ON_ERROR),
                'payload' => json_encode($payload, \JSON_THROW_ON_ERROR), 'from' => $trashedFrom,
            ],
        );
    }

    /** @return array<string, mixed>|list<mixed> */
    private static function attributesOf(Connection $db, int $id): array
    {
        /** @var array<string, mixed>|list<mixed> $attributes */
        $attributes = json_decode((string) $db->fetchOne('SELECT attributes::text FROM item WHERE id = :id', ['id' => $id]), true, 512, \JSON_THROW_ON_ERROR);

        return $attributes;
    }

    /** @return array{payload: array<string, mixed>, changes: array<string, mixed>} */
    private static function submissionOf(Connection $db, int $id): array
    {
        /** @var array{payload: string, changes: string} $row */
        $row = $db->fetchAssociative('SELECT payload::text AS payload, changes::text AS changes FROM submission WHERE id = :id', ['id' => $id]);
        /** @var array<string, mixed> $payload */
        $payload = json_decode($row['payload'], true, 512, \JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $changes */
        $changes = json_decode($row['changes'], true, 512, \JSON_THROW_ON_ERROR);

        return ['payload' => $payload, 'changes' => $changes];
    }
}
