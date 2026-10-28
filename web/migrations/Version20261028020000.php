<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * coverage_poi_cyclist() reads the label tags the 2026-10-28 country scan found
 * beside the ones it already read (coverage-provider.md §2.1): a Bett+Bike
 * reference under `ref:bettundbike`, and Accueil Vélo named in `ref:FR:label`
 * or `label`. It stays the rule of pipeline/coverage/tiles.py _CYCLIST_SQL and
 * web/assets/map/osm-tags.js cyclistLabels(); CoverageStayFacetCountTest pins
 * the three. The stays buckets are counted again with the new rule.
 */
final class Version20261028020000 extends AbstractMigration
{
    /** Version20261022010000's rule, which down() puts back. */
    private const string BEFORE = <<<'SQL'
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
        SQL;

    private const string AFTER = <<<'SQL'
        CREATE OR REPLACE FUNCTION coverage_poi_cyclist(p_tags jsonb) RETURNS boolean
        LANGUAGE sql STABLE AS $$
            SELECT COALESCE(
                   lower(btrim(p_tags->>'bettundbike')) = 'yes'
                OR (COALESCE(lower(btrim(p_tags->>'bettundbike')), '') <> 'no'
                    AND (COALESCE(btrim(p_tags->>'bett_and_bike:ref'), '') <> ''
                         OR COALESCE(btrim(p_tags->>'ref:bettundbike'), '') <> ''))
                OR lower(btrim(p_tags->>'award:accueilvelo')) ~ '^(yes|\d{4})$'
                OR CASE WHEN lower(btrim(p_tags->>'award:accueilvelo')) ~ '^\d{4}\s*-\s*\d{4}$'
                        THEN right(lower(btrim(p_tags->>'award:accueilvelo')), 4)::int >= extract(year FROM current_date)
                        ELSE false END
                OR COALESCE(lower(p_tags->>'ref:FR:label') ~ '(^|;)\s*accueil v[eé]lo\s*(;|$)', false)
                OR COALESCE(lower(p_tags->>'label') ~ '(^|;)\s*accueil v[eé]lo\s*(;|$)', false)
                OR lower(btrim(p_tags->>'bike_friend')) = 'yes', false)
        $$
        SQL;

    private const string RECOUNT_STAYS = <<<'SQL'
        DO $$
        BEGIN
            IF to_regclass('coverage_poi') IS NOT NULL THEN
                PERFORM coverage_count_refresh(NULLIF(b.country_code, ''), NULLIF(b.region_id, 0), 'O')
                   FROM (SELECT DISTINCT COALESCE(country_code, '') AS country_code, COALESCE(region_id, 0) AS region_id
                           FROM coverage_poi WHERE letter = 'O') b;
            END IF;
        END $$
        SQL;

    #[\Override]
    public function getDescription(): string
    {
        return 'coverage_poi_cyclist: also ref:bettundbike, and Accueil Vélo named in ref:FR:label or label';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(self::AFTER);
        $this->addSql(self::RECOUNT_STAYS);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql(self::BEFORE);
        $this->addSql(self::RECOUNT_STAYS);
    }
}
