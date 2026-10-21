<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Catalog\RegionDerivations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `region.silhouette`: the shape a region page, the join page and /best
 * draw (RegionSilhouette), simplified at 0.008 degrees where the ranking
 * `outline` is at 0.05, so a province shows about four times the detail. Filled
 * for every row now; RegionDerivations::shapes() keeps it current.
 */
final class Version20261020030000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'region.silhouette: the drawn region and country shape, four times the detail of the ranking outline';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE region ADD silhouette JSONB DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN region.silhouette IS 'Rings drawn on region pages (RegionSilhouette): like outline, simplified at 0.008 degrees instead of 0.05.'");
        $this->addSql(RegionDerivations::silhouetteSql());
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE region DROP silhouette');
    }
}
