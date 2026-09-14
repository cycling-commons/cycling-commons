<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A demand signal can name an area inside a country, not only the country.
 *
 * Before this, an onboarded country had nothing to say to a rider whose own
 * part of it is uncovered: the form turned into a curator application, so the
 * only way to ask for Texas was to volunteer to run it. The column is text
 * because the area being asked for has no `region` row yet, which is the whole
 * reason somebody is asking.
 *
 * Empty means the country as a whole. Not null: Postgres treats two nulls as
 * distinct, so a nullable column would let one person file the same country
 * twice and the count would stop counting people.
 *
 * @see docs/specs/moderation-and-contribution.md §11
 */
final class Version20260913200000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'country_interest carries the area asked for, and is unique per person per area';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE country_interest ADD COLUMN region_name VARCHAR(120) DEFAULT '' NOT NULL");
        $this->addSql('DROP INDEX IF EXISTS uniq_country_interest_user_cc');
        $this->addSql('CREATE UNIQUE INDEX uniq_country_interest_user_cc ON country_interest (user_id, country_code, region_name)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // The rows naming an area have no place in the two-column key, and
        // collapsing them would merge distinct people's signals into one.
        $this->addSql("DELETE FROM country_interest WHERE region_name <> ''");
        $this->addSql('DROP INDEX IF EXISTS uniq_country_interest_user_cc');
        $this->addSql('CREATE UNIQUE INDEX uniq_country_interest_user_cc ON country_interest (user_id, country_code)');
        $this->addSql('ALTER TABLE country_interest DROP COLUMN region_name');
    }
}
