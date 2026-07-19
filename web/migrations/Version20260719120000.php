<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "recommended_route.attributes.season — normalize the harvested lowercase scalar ('summer') to the canonical capitalized list (['Summer'])";
    }

    public function up(Schema $schema): void
    {
        // The harvest pipeline used to seed season as a lowercase scalar while
        // the proposal form writes a capitalized list (route-domain.md §12).
        // The importer now canonicalizes at intake; this backfills the rows
        // imported before it did. Scoped to the exact known values — anything
        // else (already-list rows) is untouched.
        $this->connection->executeStatement(
            "UPDATE recommended_route
                SET attributes = jsonb_set(attributes, '{season}', to_jsonb(ARRAY[initcap(attributes->>'season')]))
              WHERE jsonb_typeof(attributes->'season') = 'string'
                AND lower(attributes->>'season') IN ('spring', 'summer', 'autumn', 'winter')",
        );
    }

    public function down(Schema $schema): void
    {
        // One-way normalization; the scalar shape is retired (importer now
        // canonicalizes at intake), so there is nothing meaningful to restore.
    }
}
