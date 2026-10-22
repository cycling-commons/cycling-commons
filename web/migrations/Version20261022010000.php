<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The stays chips narrow the rail's count (coverage-provider.md §11): per
 * bucket, coverage_count also keeps how many shown stays (letter O) carry a
 * cyclist label (`n_cyl`), wheelchair=yes (`n_acc`), or both (`n_cyl_acc`).
 *
 * coverage_poi_cyclist() is the tile's `cyl` rule in SQL
 * (pipeline/coverage/tiles.py _CYCLIST_SQL, web/assets/map/osm-tags.js
 * cyclistLabels()): Bett+Bike, an Accueil Vélo award that has not ended, or
 * bike_friend=yes. CoverageStayFacetCountTest pins it to the same cases as
 * the pipeline test. The refresh and rebuild below are Version20260906180000's
 * with the three counts added; the triggers that call them stay as they are.
 */
final class Version20261022010000 extends AbstractMigration
{
    /** Version20260906180000's refresh and rebuild, which down() puts back. */
    private const string BEFORE_REFRESH = <<<'SQL'
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
        SQL;

    private const string BEFORE_REBUILD = <<<'SQL'
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
        SQL;

    #[\Override]
    public function getDescription(): string
    {
        return 'coverage_count: shown stays with a cyclist label, wheelchair=yes, or both, for the stays chips';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coverage_count ADD COLUMN IF NOT EXISTS n_cyl int NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE coverage_count ADD COLUMN IF NOT EXISTS n_acc int NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE coverage_count ADD COLUMN IF NOT EXISTS n_cyl_acc int NOT NULL DEFAULT 0');

        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION coverage_poi_cyclist(p_tags jsonb) RETURNS boolean
            LANGUAGE sql STABLE AS $$
                SELECT COALESCE(
                       lower(btrim(p_tags->>'bettundbike')) = 'yes'
                    OR (COALESCE(lower(btrim(p_tags->>'bettundbike')), '') <> 'no'
                        AND COALESCE(btrim(p_tags->>'bett_and_bike:ref'), '') <> '')
                    OR lower(btrim(p_tags->>'award:accueilvelo')) ~ '^(yes|\d{4})$'
                    OR CASE WHEN lower(btrim(p_tags->>'award:accueilvelo')) ~ '^\d{4}\s*-\s*\d{4}$'
                            THEN right(lower(btrim(p_tags->>'award:accueilvelo')), 4)::int >= extract(year FROM current_date)
                            ELSE false END
                    OR lower(btrim(p_tags->>'bike_friend')) = 'yes', false)
            $$
            SQL);

        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION coverage_count_refresh(p_cc text, p_rid int, p_letter text) RETURNS void
            LANGUAGE plpgsql AS $$
            BEGIN
                INSERT INTO coverage_count (country_code, region_id, letter, n, n_cyl, n_acc, n_cyl_acc)
                SELECT COALESCE(p_cc, ''), COALESCE(p_rid, 0), p_letter,
                       COUNT(*),
                       COUNT(*) FILTER (WHERE p_letter = 'O' AND coverage_poi_cyclist(cp.tags)),
                       COUNT(*) FILTER (WHERE p_letter = 'O' AND cp.tags->>'wheelchair' = 'yes'),
                       COUNT(*) FILTER (WHERE p_letter = 'O' AND coverage_poi_cyclist(cp.tags) AND cp.tags->>'wheelchair' = 'yes')
                  FROM coverage_poi cp
                 WHERE cp.letter = p_letter
                   AND COALESCE(cp.country_code, '') = COALESCE(p_cc, '')
                   AND COALESCE(cp.region_id, 0) = COALESCE(p_rid, 0)
                   AND coverage_poi_shown(cp.ref)
                ON CONFLICT (country_code, region_id, letter) DO UPDATE
                   SET n = EXCLUDED.n, n_cyl = EXCLUDED.n_cyl, n_acc = EXCLUDED.n_acc, n_cyl_acc = EXCLUDED.n_cyl_acc;
            END $$
            SQL);

        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION coverage_count_rebuild() RETURNS void
            LANGUAGE plpgsql AS $$
            BEGIN
                DELETE FROM coverage_count;
                IF to_regclass('coverage_poi') IS NULL THEN RETURN; END IF;
                INSERT INTO coverage_count (country_code, region_id, letter, n, n_cyl, n_acc, n_cyl_acc)
                SELECT COALESCE(cp.country_code, ''), COALESCE(cp.region_id, 0), cp.letter,
                       COUNT(*),
                       COUNT(*) FILTER (WHERE cp.letter = 'O' AND coverage_poi_cyclist(cp.tags)),
                       COUNT(*) FILTER (WHERE cp.letter = 'O' AND cp.tags->>'wheelchair' = 'yes'),
                       COUNT(*) FILTER (WHERE cp.letter = 'O' AND coverage_poi_cyclist(cp.tags) AND cp.tags->>'wheelchair' = 'yes')
                  FROM coverage_poi cp
                 WHERE coverage_poi_shown(cp.ref)
                 GROUP BY 1, 2, 3;
            END $$
            SQL);

        // Only the stays buckets have new numbers to fill: recount those, not the whole table.
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF to_regclass('coverage_poi') IS NOT NULL THEN
                    PERFORM coverage_count_refresh(NULLIF(b.country_code, ''), NULLIF(b.region_id, 0), 'O')
                       FROM (SELECT DISTINCT COALESCE(country_code, '') AS country_code, COALESCE(region_id, 0) AS region_id
                               FROM coverage_poi WHERE letter = 'O') b;
                END IF;
            END $$
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql(self::BEFORE_REFRESH);
        $this->addSql(self::BEFORE_REBUILD);
        $this->addSql('DROP FUNCTION IF EXISTS coverage_poi_cyclist(jsonb)');
        $this->addSql('ALTER TABLE coverage_count DROP COLUMN IF EXISTS n_cyl_acc');
        $this->addSql('ALTER TABLE coverage_count DROP COLUMN IF EXISTS n_acc');
        $this->addSql('ALTER TABLE coverage_count DROP COLUMN IF EXISTS n_cyl');
    }
}
