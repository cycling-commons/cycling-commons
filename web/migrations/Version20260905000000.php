<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The Dutch register's `type` column stops being dropped.
 *
 * `Version20260904180000` left RIVM's `type` (`Regulier, 24-7 open` /
 * `Alleen overdag bereikbaar` / `Storing`, 3067 / 152 / 68 rows) out of the
 * field map, because letter B had no field that means availability and an
 * attribute with no form field behind it is one a rider cannot correct. Letter
 * B now has `availability` (Unknown / Always / Daytime only / Ask or behind a
 * gate), so the column lands through a value map: the two open states into
 * `availability`, `Storing` into `condition` as "Out of order". The pin reads
 * both (the clock and the red "!", data-provider-hierarchy.md §6.3a), which is
 * why the spec called this the single most valuable field the dataset carries.
 *
 * A value the map does not name is dropped by the harvester, never carried.
 *
 * @see docs/specs/data-provider-hierarchy.md §11
 * @see docs/specs/edit-items/B-water-food.md
 */
final class Version20260905000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'RIVM field map: type feeds availability and condition through a value map';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE data_provider
               SET field_map = '{
                     "_layer": "alo:rivm_drinkwaterkranen_actueel",
                     "note": "beschrijvi",
                     "town": "plaats",
                     "availability": {"from": "type", "values": {"Regulier, 24-7 open": "Always", "Alleen overdag bereikbaar": "Daytime only"}},
                     "condition": {"from": "type", "values": {"Storing": "Out of order"}}
                   }'::jsonb
             WHERE provider_key = 'rivm-drinkwater'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE data_provider
               SET field_map = '{"_layer": "alo:rivm_drinkwaterkranen_actueel", "note": "beschrijvi", "town": "plaats"}'::jsonb
             WHERE provider_key = 'rivm-drinkwater'
            SQL);
    }
}
