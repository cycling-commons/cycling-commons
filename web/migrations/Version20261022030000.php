<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Catalog\RegionDerivations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `region.silhouette` as detailed as its drawing shows: each country and its
 * regions simplified at a tenth of a drawing unit of the country's frame, and
 * every part from 1.5 units across kept, so a small country such as the
 * Netherlands keeps its coast and its islands (RegionDerivations::silhouetteSql()).
 */
final class Version20261022030000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'region.silhouette: simplified at the scale of the country it is drawn in, islands kept';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("COMMENT ON COLUMN region.silhouette IS 'Rings drawn on region pages (RegionSilhouette): simplified at a tenth of a drawing unit of the country frame, parts from 1.5 units across.'");
        $this->addSql(RegionDerivations::silhouetteSql());
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // The rings stay: they are derived and the page reads them either way.
        $this->addSql("COMMENT ON COLUMN region.silhouette IS 'Rings drawn on region pages (RegionSilhouette): like outline, simplified at 0.008 degrees instead of 0.05.'");
    }
}
