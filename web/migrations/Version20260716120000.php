<?php
// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260716120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'item (letter D): backfill attributes.serviceKind from the harvested type label (t)';
    }

    public function up(Schema $schema): void
    {
        // Map the harvester's human label onto the canonical serviceKind
        // (OSM data architecture spec §5). jsonb_set adds the key in place.
        $map = [
            'Bike shop' => 'shop',
            'Repair station' => 'station',
            'Public repair station' => 'station',
            'E-bike charging station' => 'station',
            'Pump' => 'pump',
        ];
        foreach ($map as $label => $kind) {
            $this->connection->executeStatement(
                "UPDATE item
                    SET attributes = jsonb_set(attributes, '{serviceKind}', :kind::jsonb)
                  WHERE letter = 'D' AND attributes->>'t' = :label",
                ['kind' => '"'.$kind.'"', 'label' => $label],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement(
            "UPDATE item SET attributes = attributes - 'serviceKind' WHERE letter = 'D'",
        );
    }
}
