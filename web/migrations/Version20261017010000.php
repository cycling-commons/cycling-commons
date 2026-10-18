<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One Geofabrik extract may serve several countries: Geofabrik ships Ireland
 * only together with Northern Ireland (europe/ireland-and-northern-ireland,
 * GB and IE), and South Africa together with Lesotho and Eswatini. The key
 * becomes the extract and the country together.
 *
 * @see wiki/developers/data-ops/onboarding-a-country.md
 */
final class Version20261017010000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'country_extract: one extract may serve several countries, keyed by (slug, country_code)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE country_extract DROP CONSTRAINT country_extract_pkey');
        $this->addSql('ALTER TABLE country_extract ADD PRIMARY KEY (slug, country_code)');
        $this->addSql("COMMENT ON TABLE country_extract IS 'The Geofabrik extracts a country is harvested from; a shared extract has one row per country it serves. Onboarded while its country is seeded or live.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM country_extract GROUP BY slug HAVING count(*) > 1) THEN RAISE EXCEPTION 'country_extract has an extract shared by several countries; remove the extra rows first'; END IF; END \$\$");
        $this->addSql('ALTER TABLE country_extract DROP CONSTRAINT country_extract_pkey');
        $this->addSql('ALTER TABLE country_extract ADD PRIMARY KEY (slug)');
        $this->addSql("COMMENT ON TABLE country_extract IS 'The Geofabrik extracts a country is harvested from. Onboarded while its country is seeded or live.'");
    }
}
