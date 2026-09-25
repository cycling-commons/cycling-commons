<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Metre-radius searches on item get an index.
 *
 * The provider harvest asks "which item is within 50 m" on
 * `geom::geography` for every upstream row. idx_item_geom indexes `geom`,
 * which cannot serve a geography expression, so each question read the
 * table: the first RIVM run on staging (3 287 taps, 2026-09-25) took minutes
 * for a dry run. coverage_poi has had the same expression index since the
 * pipeline created it.
 */
final class Version20260925190000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'item: GiST index on geom::geography for metre-radius searches';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_item_geog ON item USING GIST ((geom::geography))');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_item_geog');
    }
}
