<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260724120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'region: precomputed border-neighbour ids (adj) for adjacency-gated cross-border scope chips';
    }

    public function up(Schema $schema): void
    {
        // Derived, recomputed every catalog import (ImportCatalogCommand::
        // recomputeAdjacency). Nullable: a region imported before the first
        // recompute carries NULL until the next import stamps it.
        $this->addSql('ALTER TABLE region ADD adj INT[] DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE region DROP adj');
    }
}
