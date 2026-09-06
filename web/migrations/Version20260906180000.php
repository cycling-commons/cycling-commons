<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Coverage counts kept by the database, so /map/coverage/counts is a sum
 * over a few thousand rows rather than a walk over two million.
 *
 * Owner, 2026-09-06: "Everywhere gets slow", and the measurement agreed: the
 * unscoped count took 26.6 s on dev against 0.2 s for one region. The count
 * has to stay right on every change to an item AND to a coverage row, and
 * the coverage rows are written by the Python pipeline, not by PHP, so the
 * bookkeeping lives in the database: one small table, one predicate that
 * mirrors CoverageRepository's "is this coverage row still shown" rule, and
 * statement-level triggers on both sides that recount only the buckets a
 * statement touched.
 *
 * coverage_poi is pipeline-owned DDL and may not exist yet on a fresh
 * cluster, so its triggers are installed by coverage_count_install(), which
 * this migration calls if the table is there, the pipeline calls after
 * ensure_schema(), and the test schema trait calls after creating the table.
 *
 * @see docs/specs/coverage-provider.md §11
 */
final class Version20260906180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'coverage_count: per (country, region, letter) counts kept by triggers, read by /map/coverage/counts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS coverage_count (
                country_code char(2) NOT NULL DEFAULT '',
                region_id    int     NOT NULL DEFAULT 0,
                letter       char(1) NOT NULL,
                n            int     NOT NULL DEFAULT 0,
                PRIMARY KEY (country_code, region_id, letter)
            )
            SQL);

        // THE predicate, in SQL: a coverage row is shown unless a served item
        // claims its ref and that item is more than an untouched OSM import.
        // Mirrors CoverageRepository::counts() and CoverageRetirement::untouchedOsmSql();
        // CoverageCountTest::testTheCountTableAgreesWithALiveCount keeps them in step.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION coverage_poi_shown(p_ref text) RETURNS boolean
            LANGUAGE sql STABLE AS $$
                SELECT NOT EXISTS (
                    SELECT 1 FROM item i
                     WHERE p_ref IN (i.source_ref, i.osm_ref)
                       AND i.state IN ('unverified', 'verified')
                       AND NOT (
                            i.source = 'osm' AND i.state = 'unverified'
                            AND NOT EXISTS (SELECT 1 FROM change_history ch WHERE ch.item_id = i.id)
                            AND NOT EXISTS (SELECT 1 FROM item_confirmation ic WHERE ic.item_id = i.id)
                            AND NOT EXISTS (SELECT 1 FROM submission sb WHERE sb.item_id = i.id)
                       )
                )
            $$
            SQL);

        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION coverage_count_refresh(p_cc text, p_rid int, p_letter text) RETURNS void
            LANGUAGE plpgsql AS $$
            BEGIN
                INSERT INTO coverage_count (country_code, region_id, letter, n)
                SELECT COALESCE(p_cc, ''), COALESCE(p_rid, 0), p_letter,
                       (SELECT COUNT(*) FROM coverage_poi cp
                         WHERE cp.letter = p_letter
                           AND COALESCE(cp.country_code, '') = COALESCE(p_cc, '')
                           AND COALESCE(cp.region_id, 0) = COALESCE(p_rid, 0)
                           AND coverage_poi_shown(cp.ref))
                ON CONFLICT (country_code, region_id, letter) DO UPDATE SET n = EXCLUDED.n;
            END $$
            SQL);

        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION coverage_count_rebuild() RETURNS void
            LANGUAGE plpgsql AS $$
            BEGIN
                DELETE FROM coverage_count;
                IF to_regclass('coverage_poi') IS NULL THEN RETURN; END IF;
                INSERT INTO coverage_count (country_code, region_id, letter, n)
                SELECT COALESCE(cp.country_code, ''), COALESCE(cp.region_id, 0), cp.letter, COUNT(*)
                  FROM coverage_poi cp
                 WHERE coverage_poi_shown(cp.ref)
                 GROUP BY 1, 2, 3;
            END $$
            SQL);

        // Recount every bucket a set of coverage refs lives in.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION coverage_count_refresh_refs(p_refs text[]) RETURNS void
            LANGUAGE plpgsql AS $$
            DECLARE b record;
            BEGIN
                IF to_regclass('coverage_poi') IS NULL OR p_refs IS NULL THEN RETURN; END IF;
                FOR b IN SELECT DISTINCT cp.country_code, cp.region_id, cp.letter
                           FROM coverage_poi cp WHERE cp.ref = ANY (p_refs)
                LOOP
                    PERFORM coverage_count_refresh(b.country_code, b.region_id, b.letter);
                END LOOP;
            END $$
            SQL);

        // coverage_poi side: the buckets the statement touched, old and new.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION coverage_count_on_poi() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE b record;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    FOR b IN SELECT DISTINCT country_code, region_id, letter FROM new_rows LOOP
                        PERFORM coverage_count_refresh(b.country_code, b.region_id, b.letter);
                    END LOOP;
                ELSIF TG_OP = 'DELETE' THEN
                    FOR b IN SELECT DISTINCT country_code, region_id, letter FROM old_rows LOOP
                        PERFORM coverage_count_refresh(b.country_code, b.region_id, b.letter);
                    END LOOP;
                ELSE
                    FOR b IN SELECT DISTINCT country_code, region_id, letter FROM old_rows
                             UNION SELECT DISTINCT country_code, region_id, letter FROM new_rows LOOP
                        PERFORM coverage_count_refresh(b.country_code, b.region_id, b.letter);
                    END LOOP;
                END IF;
                RETURN NULL;
            END $$
            SQL);

        // item side: the refs an item claims, before and after.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION coverage_count_on_item() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE refs text[];
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    SELECT array_agg(r) INTO refs FROM (SELECT source_ref AS r FROM new_rows UNION SELECT osm_ref FROM new_rows) u WHERE r IS NOT NULL;
                ELSIF TG_OP = 'DELETE' THEN
                    SELECT array_agg(r) INTO refs FROM (SELECT source_ref AS r FROM old_rows UNION SELECT osm_ref FROM old_rows) u WHERE r IS NOT NULL;
                ELSE
                    SELECT array_agg(r) INTO refs FROM (
                        SELECT source_ref AS r FROM old_rows UNION SELECT osm_ref FROM old_rows
                        UNION SELECT source_ref FROM new_rows UNION SELECT osm_ref FROM new_rows) u WHERE r IS NOT NULL;
                END IF;
                PERFORM coverage_count_refresh_refs(refs);
                RETURN NULL;
            END $$
            SQL);

        // A first submission, check or history row turns an untouched OSM
        // import into a curated row, which hides its twin: same recount.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION coverage_count_on_touch() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE ids int[]; refs text[];
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    SELECT array_agg(DISTINCT item_id) INTO ids FROM new_rows WHERE item_id IS NOT NULL;
                ELSIF TG_OP = 'DELETE' THEN
                    SELECT array_agg(DISTINCT item_id) INTO ids FROM old_rows WHERE item_id IS NOT NULL;
                ELSE
                    SELECT array_agg(DISTINCT item_id) INTO ids FROM (SELECT item_id FROM old_rows UNION SELECT item_id FROM new_rows) u WHERE item_id IS NOT NULL;
                END IF;
                IF ids IS NULL THEN RETURN NULL; END IF;
                SELECT array_agg(r) INTO refs FROM (
                    SELECT i.source_ref AS r FROM item i WHERE i.id = ANY (ids)
                    UNION SELECT i.osm_ref FROM item i WHERE i.id = ANY (ids)) u WHERE r IS NOT NULL;
                PERFORM coverage_count_refresh_refs(refs);
                RETURN NULL;
            END $$
            SQL);

        // Installs the coverage_poi triggers when that table exists. Idempotent;
        // called here, by the pipeline after ensure_schema(), and by the tests.
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
                IF NOT EXISTS (SELECT 1 FROM coverage_count) THEN PERFORM coverage_count_rebuild(); END IF;
            END $$
            SQL);

        foreach (['item' => 'coverage_count_on_item', 'change_history' => 'coverage_count_on_touch', 'item_confirmation' => 'coverage_count_on_touch', 'submission' => 'coverage_count_on_touch'] as $table => $fn) {
            $this->addSql(sprintf('CREATE OR REPLACE TRIGGER coverage_count_%1$s_ins AFTER INSERT ON %1$s REFERENCING NEW TABLE AS new_rows FOR EACH STATEMENT EXECUTE FUNCTION %2$s()', $table, $fn));
            $this->addSql(sprintf('CREATE OR REPLACE TRIGGER coverage_count_%1$s_upd AFTER UPDATE ON %1$s REFERENCING OLD TABLE AS old_rows NEW TABLE AS new_rows FOR EACH STATEMENT EXECUTE FUNCTION %2$s()', $table, $fn));
            $this->addSql(sprintf('CREATE OR REPLACE TRIGGER coverage_count_%1$s_del AFTER DELETE ON %1$s REFERENCING OLD TABLE AS old_rows FOR EACH STATEMENT EXECUTE FUNCTION %2$s()', $table, $fn));
        }

        // The one full count. 26 s on dev; minutes on prod. Once.
        $this->addSql('SELECT coverage_count_install()');
    }

    public function down(Schema $schema): void
    {
        foreach (['item', 'change_history', 'item_confirmation', 'submission'] as $table) {
            foreach (['ins', 'upd', 'del'] as $op) {
                $this->addSql(sprintf('DROP TRIGGER IF EXISTS coverage_count_%s_%s ON %s', $table, $op, $table));
            }
        }
        $this->addSql(<<<'SQL'
            DO $$ BEGIN
                IF to_regclass('coverage_poi') IS NOT NULL THEN
                    DROP TRIGGER IF EXISTS coverage_count_poi_ins ON coverage_poi;
                    DROP TRIGGER IF EXISTS coverage_count_poi_upd ON coverage_poi;
                    DROP TRIGGER IF EXISTS coverage_count_poi_del ON coverage_poi;
                END IF;
            END $$
            SQL);
        foreach (['coverage_count_install()', 'coverage_count_on_touch()', 'coverage_count_on_item()', 'coverage_count_on_poi()', 'coverage_count_refresh_refs(text[])', 'coverage_count_rebuild()', 'coverage_count_refresh(text, int, text)', 'coverage_poi_shown(text)'] as $fn) {
            $this->addSql('DROP FUNCTION IF EXISTS '.$fn);
        }
        $this->addSql('DROP TABLE IF EXISTS coverage_count');
    }
}
