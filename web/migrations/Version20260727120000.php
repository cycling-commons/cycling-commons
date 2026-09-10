<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Catalog\Command\ImportCatalogCommand;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260727120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'region: simplified ranking outline (outline) so scope chips rank by polygon-edge distance instead of bbox centre';
    }

    public function up(Schema $schema): void
    {
        // Derived, recomputed every catalog import beside adj
        // (ImportCatalogCommand::recomputeOutlines). Nullable so the column can
        // be added before anything computes it; the client treats NULL, [] and
        // a malformed value alike and falls back to the bbox centre.
        $this->addSql('ALTER TABLE region ADD outline JSONB DEFAULT NULL');
        // Backfill the regions already onboarded, so the ranking improves without
        // waiting for the next import. Same statement the import runs, by
        // reference rather than by copy — the two must never drift.
        $this->addSql(ImportCatalogCommand::OUTLINE_SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE region DROP outline');
    }
}
