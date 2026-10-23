<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `region.facts_harvest`: a region's figures fetched at build time, beside
 * the ones people set (`region.facts`): for now the population and the year
 * it was counted, from Wikidata (`app:regions:import-population`). Its own
 * column, so an import can never overwrite what a person set; the page shows
 * a person's figure over the fetched one.
 */
final class Version20261023020000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'region.facts_harvest: build-time figures (Wikidata population with its year) beside region.facts';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE region ADD facts_harvest JSONB DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN region.facts_harvest IS 'Figures fetched at build time (app:regions:import-population): population {value, year, qid} from Wikidata. A figure in region.facts, set by a person, shows over it.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE region DROP facts_harvest');
    }
}
