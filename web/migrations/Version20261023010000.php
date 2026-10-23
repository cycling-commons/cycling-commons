<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `region.facts`: the figures at the top of a region page that a person
 * sets on the geography form (RegionFacts): a stated area, the population
 * and the year it was counted, and the ride character. Empty for every row
 * until somebody sets one; `area_km2` stays the measured area.
 */
final class Version20261023010000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'region.facts: stated area, population with its year, and ride character for the region page';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE region ADD facts JSONB DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN region.facts IS 'Figures at the top of the region page, set through approved proposals (RegionFacts): area (stated km2, over the measured area_km2), population with its year, ride character flat|hilly|mountainous; each entry names the submission that set it.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE region DROP facts');
    }
}
