<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260825120000 extends AbstractMigration
{
    /**
     * Old letter => new letter, in an order where every target letter is free
     * at the moment it is written. That matters: item has
     * UNIQUE (source, source_ref, letter) and coverage_poi has UNIQUE (ref, letter),
     * neither deferrable, so one row per statement per letter is the safe shape.
     * A single CASE update over the whole table would trip the unique index on
     * a ref that carries two letters (a hotel that is also a castle: E and J).
     *
     * Practical A-M, experiential N-Z (owner decision 2026-08-25):
     *   A surface, B water, C toilets, D services, E hazards, F transit, G shelter,
     *   N climbs, O stays, P scenic, Q history, R routes.
     *
     * @var list<array{string, string}>
     */
    private const array UP = [
        ['B', 'N'], // climbs
        ['K', 'R'], // recommended routes
        ['J', 'Q'], // history & culture
        ['I', 'P'], // scenic views
        ['E', 'O'], // where to sleep
        ['C', 'B'], // water & food (B is free now)
        ['M', 'C'], // public toilets (C is free now)
        ['F', 'E'], // hazards (E is free now)
        ['G', 'F'], // getting there (F is free now)
        ['H', 'G'], // shelter (G is free now)
    ];

    private const array TABLES = ['item', 'coverage_poi', 'submission'];

    public function getDescription(): string
    {
        return 'Catalogue letters renumbered: practical A-M, experiential N-Z (item, coverage_poi, submission)';
    }

    public function up(Schema $schema): void
    {
        // The coverage tile artifact is keyed `<letter>_<cc>` (coverage-provider.md §4),
        // so it has to be republished after this runs; until then the map reads
        // coverage layers under the old names and finds nothing. The Redis
        // catalog cache must be cleared as well (cache:pool:clear).
        foreach (self::TABLES as $table) {
            foreach (self::UP as [$old, $new]) {
                // coverage_poi is pipeline-owned (load.py) and absent from a database only these migrations built (the test DB, a fresh clone): guard it, never assume it.
                $this->addSql(\sprintf("DO $$ BEGIN IF to_regclass('%s') IS NOT NULL THEN UPDATE %s SET letter = '%s' WHERE letter = '%s'; END IF; END $$", $table, $table, $new, $old));
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            foreach (array_reverse(self::UP) as [$old, $new]) {
                // coverage_poi is pipeline-owned (load.py) and absent from a database only these migrations built (the test DB, a fresh clone): guard it, never assume it.
                $this->addSql(\sprintf("DO $$ BEGIN IF to_regclass('%s') IS NOT NULL THEN UPDATE %s SET letter = '%s' WHERE letter = '%s'; END IF; END $$", $table, $table, $old, $new));
            }
        }
    }
}
