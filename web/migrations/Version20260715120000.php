<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260715120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'item.attributes.openingHours — retire free-text opening hours; reset stored values to the "Unknown" option (bike services D, history/culture J)';
    }

    public function up(Schema $schema): void
    {
        // Opening hours moved from free text to a 3-option select
        // (Unknown / 24/7 / See website). Specific weekly hours change without
        // notice and we can't verify them, so every previously-typed value —
        // including old '24/7' free text — is reset to the honest default.
        // jsonb_exists(), not the `?` operator: DBAL/PDO would bind `?` as a
        // positional placeholder and the statement would fail to parse.
        $this->connection->executeStatement(
            "UPDATE item
               SET attributes = jsonb_set(attributes, '{openingHours}', '\"Unknown\"'::jsonb)
             WHERE letter IN ('D', 'J')
               AND jsonb_exists(attributes, 'openingHours')",
        );
    }

    public function down(Schema $schema): void
    {
        // One-way normalization; the original free-text strings are not recoverable.
    }
}
