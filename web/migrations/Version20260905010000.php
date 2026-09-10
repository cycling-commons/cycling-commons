<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A RIVM tap is drinking water: say so on the row.
 *
 * The register is the national list of PUBLIC DRINKING-WATER taps, so every
 * row in it answers the potability question by being in it. Without the field
 * the map drew the unfilled "nobody said" drop and the drawer read "Nobody has
 * tagged whether this is drinkable", which was the first thing the owner saw
 * on the first RIVM pin (2026-09-05). The value map has no constant, and does
 * not need one: every `type` the register uses maps to the same answer, and a
 * new `type` value appearing upstream would then land as unknown, which is
 * the honest reading of a value nobody has looked at.
 *
 * @see docs/specs/data-provider-hierarchy.md §11
 */
final class Version20260905010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'RIVM field map: every register row is potable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE data_provider
               SET field_map = field_map || '{
                     "potable": {"from": "type", "values": {"Regulier, 24-7 open": "Yes (public supply)", "Alleen overdag bereikbaar": "Yes (public supply)", "Storing": "Yes (public supply)"}}
                   }'::jsonb
             WHERE provider_key = 'rivm-drinkwater'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE data_provider SET field_map = field_map - 'potable' WHERE provider_key = 'rivm-drinkwater'");
    }
}
