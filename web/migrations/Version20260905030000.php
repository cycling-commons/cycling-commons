<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What every RIVM tap has in common, said once on the row.
 *
 * Owner, 2026-09-05, who knows the register: the taps are all free, all
 * bottle-friendly, and all shut against frost in winter. The register does not
 * carry those columns because they are true of every row, so the field map
 * says them through the value map: every `type` the register uses lands on the
 * same answer. Availability stays RIVM's own column (152 are daytime-only),
 * which this migration does not touch.
 *
 * @see docs/specs/data-provider-hierarchy.md §11
 */
final class Version20260905030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'RIVM field map: free, bottle-friendly, frost-shut in winter, for every row';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE data_provider
               SET field_map = field_map || '{
                     "cost": {"from": "type", "values": {"Regulier, 24-7 open": "Free", "Alleen overdag bereikbaar": "Free", "Storing": "Free"}},
                     "bottleFill": {"from": "type", "values": {"Regulier, 24-7 open": "Yes", "Alleen overdag bereikbaar": "Yes", "Storing": "Yes"}},
                     "seasonal": {"from": "type", "values": {"Regulier, 24-7 open": "Frost-shut in winter", "Alleen overdag bereikbaar": "Frost-shut in winter", "Storing": "Frost-shut in winter"}}
                   }'::jsonb
             WHERE provider_key = 'rivm-drinkwater'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE data_provider SET field_map = field_map - 'cost' - 'bottleFill' - 'seasonal' WHERE provider_key = 'rivm-drinkwater'");
    }
}
