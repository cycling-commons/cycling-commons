<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A provider's defaults: what a harvest fills in where the provider's own data
 * is silent (owner 2026-10-01: "The harvester should be able to set defaults
 * for these fields. Only 'Still as mapped?' should be empty by default").
 *
 * `data_provider.defaults` holds `{letter: {field: value}}` in that letter's
 * form vocabulary. App\Provider\ProviderRegistry refuses anything else, and
 * App\Provider\ProviderHarvest fills a default only into a gap.
 *
 * The RIVM row said four of these through its field map, by mapping each
 * value of the register's `type` column to the same constant, so a new `type`
 * value upstream would have dropped all four. They move to `defaults`, with
 * the tap's own `type` ("Drinking tap"), which the register never said at all.
 * The field map keeps what the register's data really carries: availability
 * and "Storing" from `type`, the note, the town and the layer.
 *
 * Existing rows get the defaults the way a harvest would give them: only where
 * the attribute is absent or empty, never over a stored value, never on a
 * field a person changed (its `change_history`), and never `condition`. The
 * UPDATE fires `catalog_change`, so the regions' stamps move and the map
 * rebuilds.
 *
 * @see docs/specs/data-provider-hierarchy.md §5.2
 */
final class Version20261001220000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'data_provider.defaults; RIVM constants move from field_map to defaults, empty attributes of existing rows filled';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE data_provider ADD defaults JSONB DEFAULT '{}'::jsonb NOT NULL");
        $this->addSql("COMMENT ON COLUMN data_provider.defaults IS 'Per letter, field => value in that letter''s form vocabulary: what a harvest fills where the provider''s data is empty. Never condition. Written only by ProviderRegistry.'");

        $this->addSql(<<<'SQL'
            UPDATE data_provider
               SET defaults = '{"B": {"bottleFill": "Yes", "cost": "Free", "potable": "Yes (public supply)", "seasonal": "Frost-shut in winter", "type": "Drinking tap"}}'::jsonb,
                   field_map = field_map - 'cost' - 'potable' - 'seasonal' - 'bottleFill'
             WHERE provider_key = 'rivm-drinkwater'
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE item i
               SET attributes = i.attributes || fill.add
              FROM (
                    SELECT t.id, jsonb_object_agg(d.key, d.value) AS add
                      FROM item t
                      JOIN data_provider p ON p.id = t.provider_id
                      CROSS JOIN LATERAL jsonb_each(COALESCE(p.defaults -> t.letter, '{}'::jsonb)) d
                     WHERE d.key NOT IN ('condition', 'stillPresent')
                       AND (t.attributes -> d.key IS NULL OR t.attributes -> d.key IN ('null'::jsonb, '""'::jsonb))
                       AND NOT EXISTS (SELECT 1 FROM change_history h WHERE h.item_id = t.id AND h.field = d.key)
                     GROUP BY t.id
                   ) fill
             WHERE i.id = fill.id
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // The filled attributes stay: they are the values the old field map
        // wrote on every row, plus `type`, which a rider can change.
        $this->addSql(<<<'SQL'
            UPDATE data_provider
               SET field_map = field_map || '{
                     "potable": {"from": "type", "values": {"Regulier, 24-7 open": "Yes (public supply)", "Alleen overdag bereikbaar": "Yes (public supply)", "Storing": "Yes (public supply)"}},
                     "cost": {"from": "type", "values": {"Regulier, 24-7 open": "Free", "Alleen overdag bereikbaar": "Free", "Storing": "Free"}},
                     "bottleFill": {"from": "type", "values": {"Regulier, 24-7 open": "Yes", "Alleen overdag bereikbaar": "Yes", "Storing": "Yes"}},
                     "seasonal": {"from": "type", "values": {"Regulier, 24-7 open": "Frost-shut in winter", "Alleen overdag bereikbaar": "Frost-shut in winter", "Storing": "Frost-shut in winter"}}
                   }'::jsonb
             WHERE provider_key = 'rivm-drinkwater'
            SQL);
        $this->addSql('ALTER TABLE data_provider DROP defaults');
    }
}
