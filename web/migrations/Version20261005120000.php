<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A country is rows, not code: country, its Geofabrik extracts, the plan
 * waiting to become region rows, and the region display labels.
 *
 * @see wiki/developers/data-ops/onboarding-a-country.md
 */
final class Version20261005120000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'country, country_extract, country_plan_region, region.labels: a country is rows, not code';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE country (code CHAR(2) NOT NULL, name VARCHAR(100) NOT NULL, subtype VARCHAR(20) NOT NULL, bbox JSONB DEFAULT NULL, labels JSONB DEFAULT '{}' NOT NULL, timezones TEXT[] DEFAULT '{}' NOT NULL, status VARCHAR(10) NOT NULL, overture_release VARCHAR(40) DEFAULT NULL, planned_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, seeded_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, live_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (code))");
        $this->addSql("ALTER TABLE country ADD CONSTRAINT country_status_check CHECK (status IN ('planned', 'seeded', 'live'))");
        $this->addSql('CREATE TABLE country_extract (slug VARCHAR(100) NOT NULL, country_code CHAR(2) NOT NULL, PRIMARY KEY (slug))');
        $this->addSql('CREATE INDEX idx_country_extract_country ON country_extract (country_code)');
        $this->addSql('ALTER TABLE country_extract ADD CONSTRAINT fk_country_extract_country FOREIGN KEY (country_code) REFERENCES country (code) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql("CREATE TABLE country_plan_region (country_code CHAR(2) NOT NULL, slug VARCHAR(80) NOT NULL, iso_code VARCHAR(10) DEFAULT NULL, name VARCHAR(160) NOT NULL, labels JSONB DEFAULT '{}' NOT NULL, fallback_locales TEXT[] DEFAULT '{}' NOT NULL, admin_level SMALLINT NOT NULL, area_km2 DOUBLE PRECISION NOT NULL, geom geometry(Geometry, 4326) NOT NULL, PRIMARY KEY (country_code, slug))");
        $this->addSql('ALTER TABLE country_plan_region ADD CONSTRAINT fk_country_plan_region_country FOREIGN KEY (country_code) REFERENCES country (code) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql("ALTER TABLE region ADD labels JSONB DEFAULT '{}' NOT NULL");
        $this->addSql("COMMENT ON TABLE country IS 'One row per onboarded country: planned, then seeded (region rows exist, the harvest may run), then live.'");
        $this->addSql("COMMENT ON TABLE country_extract IS 'The Geofabrik extracts a country is harvested from. Onboarded while its country is seeded or live.'");
        $this->addSql("COMMENT ON TABLE country_plan_region IS 'A confirmed onboarding plan before it becomes region rows: written by the planner or import-plan, read by app:country:apply.'");
        $this->addSql("COMMENT ON COLUMN region.labels IS 'Display label per locale. RegionLabels falls back to en, then to name.'");
        $this->addSql("COMMENT ON COLUMN country.timezones IS 'IANA zones whose visitors the map hints at this country (CountryTimezones). Filled by app:country:apply when empty.'");
        $this->addSql("COMMENT ON COLUMN country_plan_region.fallback_locales IS 'Locales whose label is the native name because Overture had none in that language.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE region DROP labels');
        $this->addSql('DROP TABLE country_plan_region');
        $this->addSql('DROP TABLE country_extract');
        $this->addSql('DROP TABLE country');
    }
}
