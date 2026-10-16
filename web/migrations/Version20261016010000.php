<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bike-service rows without a `serviceKind` get the one their label `t` names
 * (owner 2026-10-16): rows the manual seed wrote after the July backfill
 * (Version20260716120000) carried only the label, so a repair station drew
 * the shop icon on every map. The same labels and kinds as that backfill and
 * ServiceKind::fromLegacyLabel; a row that has a kind keeps it.
 */
final class Version20261016010000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Bike-service rows without a serviceKind get the one their label names.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $map = [
            'Bike shop' => 'shop',
            'Repair station' => 'station',
            'Public repair station' => 'station',
            'E-bike charging station' => 'station',
            'Pump' => 'pump',
        ];
        foreach ($map as $label => $kind) {
            $this->addSql(
                "UPDATE item
                    SET attributes = jsonb_set(attributes, '{serviceKind}', to_jsonb(:kind::text)), updated_at = NOW()
                  WHERE letter = 'D' AND attributes->>'t' = :label
                    AND (attributes->>'serviceKind' IS NULL OR attributes->>'serviceKind' = '')",
                ['kind' => $kind, 'label' => $label],
            );
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // Nothing to undo safely: a kind set here is the kind the row's own label names.
    }
}
