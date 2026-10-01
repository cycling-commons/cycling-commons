<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * RIVM stops writing a town onto its taps, and the town it wrote goes.
 *
 * The water form (letter B) has no town field, so a rider could never correct
 * it, and a tap has its point (owner 2026-10-01: "We have the gps so we do not
 * need a town"). The field map loses `town`, and every RIVM item loses the
 * `town` attribute the harvest put there; nobody can have edited it, because
 * no form offers it. Each item write moves its region's stamp by trigger
 * (`catalog_change`), so the map documents rebuild.
 *
 * @see docs/specs/data-provider-hierarchy.md §11
 */
final class Version20261001230000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'RIVM: no town in the field map, and the harvested town attribute removed from its items';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE data_provider SET field_map = field_map - 'town' WHERE provider_key = 'rivm-drinkwater' AND jsonb_exists(field_map, 'town')");
        $this->addSql("UPDATE item SET attributes = attributes - 'town'
                        WHERE jsonb_exists(attributes, 'town')
                          AND provider_id = (SELECT id FROM data_provider WHERE provider_key = 'rivm-drinkwater')");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // The removed town names are not kept; a refresh with `town` back in
        // the field map would write them again.
        $this->addSql("UPDATE data_provider SET field_map = field_map || '{\"town\": \"plaats\"}'::jsonb WHERE provider_key = 'rivm-drinkwater'");
    }
}
