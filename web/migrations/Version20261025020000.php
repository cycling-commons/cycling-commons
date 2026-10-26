<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Regions added to a live country: `python -m onboarding.plan US --add US-AZ`
 * plans more regions for a country that is already on the map. Its extracts
 * wait in `country_plan_extract` until apply, so a nightly harvest never
 * reads them before their regions exist, and the new regions carry
 * `region.pending` until `app:country:mark-live` clears it, so the map shows
 * them only once every step passed, as it does for a whole country.
 */
final class Version20261025020000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'region.pending + country_plan_extract: regions added to a live country show once every onboarding step passed';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE region ADD pending BOOLEAN DEFAULT false NOT NULL');
        $this->addSql("COMMENT ON COLUMN region.pending IS 'A region added to a live country whose onboarding has not finished (app:country:apply sets it, app:country:mark-live clears it). The map does not show it yet.'");
        $this->addSql('CREATE TABLE country_plan_extract (slug VARCHAR(100) NOT NULL, country_code CHAR(2) NOT NULL, PRIMARY KEY (slug, country_code))');
        $this->addSql('ALTER TABLE country_plan_extract ADD CONSTRAINT fk_country_plan_extract_country FOREIGN KEY (country_code) REFERENCES country (code) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql("COMMENT ON TABLE country_plan_extract IS 'The Geofabrik extracts of regions planned for a live country. app:country:apply moves them to country_extract, so the harvest reads them only once their regions exist.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE country_plan_extract');
        $this->addSql('ALTER TABLE region DROP pending');
    }
}
