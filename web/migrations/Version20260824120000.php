<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Catalog\Command\ImportCatalogCommand;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'region.adj: drop level-2 country outlines from every neighbour list';
    }

    public function up(Schema $schema): void
    {
        // Every province intersects its own country outline, so the first
        // adjacency recompute made the level-2 row a neighbour of all of them.
        // The map's spotlight clears region + neighbours, so selecting North
        // Holland lit the whole Netherlands and left the real neighbour tier
        // invisible (owner 2026-08-24).
        //
        // Derived data: this is the corrected recompute, run once, so a
        // deployed database is fixed without waiting for the next catalog
        // import. Same SQL the command uses, by construction.
        $this->addSql(ImportCatalogCommand::adjacencySql());
    }

    public function down(Schema $schema): void
    {
        // The pre-fix state is "every region also lists its country outline",
        // which is the bug. Recomputing it would be restoring a defect, so
        // down() deliberately leaves the corrected data in place: adj is
        // derived and any import rewrites it anyway.
        $this->addSql('SELECT 1');
    }
}
