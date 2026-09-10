<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The bucket recount reaches its bucket through an index.
 *
 * coverage_count_refresh() addresses a bucket as
 * `COALESCE(country_code, '') = ... AND COALESCE(region_id, 0) = ...`, so a
 * NULL country or region has a bucket of its own. That is right, and it is
 * also why coverage_poi_bucket_idx (country_code, region_id, letter) was never
 * used: the planner fell back to the letter index and walked every row of
 * that letter, 476 000 for history, calling coverage_poi_shown() on the way.
 * One recount took 350 ms, and a curator's one-tap confirm fires six of them
 * (the item insert, its submission, two history rows, the confirmation, the
 * state flip), so the tap took 5.5 s (owner 2026-09-10: "just takes a long
 * time"). An index on the same COALESCE expressions brings a recount to
 * 29 ms with the predicate untouched, and the predicate stays defined once.
 *
 * The claim lookup inside coverage_poi_shown() reads item by source_ref,
 * which had no index of its own (uniq_item_source_ref_letter leads on
 * source); it gets one.
 *
 * coverage_count_install() creates the expression index too, so a
 * coverage_poi table the pipeline or the test trait creates gets it.
 *
 * @see docs/specs/coverage-provider.md §11
 */
final class Version20260910150000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'coverage_count: a bucket index the recount can use, and item.source_ref indexed';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_item_source_ref ON item (source_ref)');
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION coverage_count_install() RETURNS void
            LANGUAGE plpgsql AS $$
            BEGIN
                IF to_regclass('coverage_poi') IS NULL THEN RETURN; END IF;
                CREATE OR REPLACE TRIGGER coverage_count_poi_ins AFTER INSERT ON coverage_poi
                    REFERENCING NEW TABLE AS new_rows FOR EACH STATEMENT EXECUTE FUNCTION coverage_count_on_poi();
                CREATE OR REPLACE TRIGGER coverage_count_poi_upd AFTER UPDATE ON coverage_poi
                    REFERENCING OLD TABLE AS old_rows NEW TABLE AS new_rows FOR EACH STATEMENT EXECUTE FUNCTION coverage_count_on_poi();
                CREATE OR REPLACE TRIGGER coverage_count_poi_del AFTER DELETE ON coverage_poi
                    REFERENCING OLD TABLE AS old_rows FOR EACH STATEMENT EXECUTE FUNCTION coverage_count_on_poi();
                CREATE INDEX IF NOT EXISTS coverage_poi_bucket_idx ON coverage_poi (country_code, region_id, letter);
                -- The recount addresses a bucket through COALESCE, so the index must too.
                CREATE INDEX IF NOT EXISTS coverage_poi_bucket_key_idx ON coverage_poi ((COALESCE(country_code, '')), (COALESCE(region_id, 0)), letter);
                IF NOT EXISTS (SELECT 1 FROM coverage_count) THEN PERFORM coverage_count_rebuild(); END IF;
            END $$
            SQL);
        $this->addSql('SELECT coverage_count_install()');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS coverage_poi_bucket_key_idx');
        $this->addSql('DROP INDEX IF EXISTS idx_item_source_ref');
    }
}
