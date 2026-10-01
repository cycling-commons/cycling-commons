<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The water type "Café — refill point" is now spelled "Café - refill point"
 * (owner 2026-10-01). A stored place still carrying the old spelling would
 * match no choice in the form any more, so it is renamed with the vocabulary.
 * Each item write moves its region's stamp by trigger (`catalog_change`).
 *
 * @see docs/specs/edit-items/B-water-food.md
 */
final class Version20261001233000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Water type: "Café — refill point" stored on places becomes "Café - refill point"';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE item SET attributes = jsonb_set(attributes, '{type}', to_jsonb('Café - refill point'::text))
                        WHERE letter = 'B' AND attributes->>'type' = 'Café — refill point'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE item SET attributes = jsonb_set(attributes, '{type}', to_jsonb('Café — refill point'::text))
                        WHERE letter = 'B' AND attributes->>'type' = 'Café - refill point'");
    }
}
