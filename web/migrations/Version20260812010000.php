<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retire two parochial A-layer vocabulary values
 * (docs/specs/edit-items/A-road-surface.md).
 *
 * `Car-free (RAVeL)` named one region's greenway brand in a dropdown that now
 * describes twelve countries. The plain `Car-free` it becomes is not a new
 * value: it is what the Wallonia harvester has been writing all along, so this
 * removes a second spelling of one fact rather than inventing a third.
 *
 * `Forestry` did not say what closes the road. `Forestry work` does.
 *
 * Data-only: values live in `item.attributes` (jsonb), so there is no schema
 * change and nothing to rebuild.
 *
 * **`down()` deliberately does not reverse the traffic merge.** 132 rows
 * already said `Car-free` before this ran and one said `Car-free (RAVeL)`;
 * afterwards all 133 say `Car-free`, and nothing records which was which. A
 * reverse that renamed them all would corrupt 132 rows it never touched, so it
 * restores only the closure value — where `Forestry work` is new and therefore
 * unambiguous. Merging two spellings into one is a one-way door, and pretending
 * otherwise is worse than saying so.
 */
final class Version20260812010000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'A-layer vocabulary: Car-free (RAVeL) → Car-free, Forestry → Forestry work';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE item SET attributes = jsonb_set(attributes, '{traffic}', '\"Car-free\"')
                       WHERE attributes->>'traffic' = 'Car-free (RAVeL)'");
        $this->addSql("UPDATE item SET attributes = jsonb_set(attributes, '{seasonalClosure}', '\"Forestry work\"')
                       WHERE attributes->>'seasonalClosure' = 'Forestry'");
        // Undecided submissions carry the proposed value in their payload, so a
        // curator approving one tomorrow would write the retired spelling back
        // into the item this migration just cleaned.
        $this->addSql("UPDATE submission SET payload = replace(payload::text, 'Car-free (RAVeL)', 'Car-free')::jsonb
                       WHERE payload::text LIKE '%Car-free (RAVeL)%'");
        $this->addSql("UPDATE submission SET payload = replace(payload::text, '\"Forestry\"', '\"Forestry work\"')::jsonb
                       WHERE payload::text LIKE '%\"Forestry\"%'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // Traffic is NOT reversed — see the class docblock. Restoring the
        // parenthetical would rename 132 rows that never carried it.
        $this->addSql("UPDATE item SET attributes = jsonb_set(attributes, '{seasonalClosure}', '\"Forestry\"')
                       WHERE attributes->>'seasonalClosure' = 'Forestry work'");
        $this->addSql("UPDATE submission SET payload = replace(payload::text, '\"Forestry work\"', '\"Forestry\"')::jsonb
                       WHERE payload::text LIKE '%\"Forestry work\"%'");
    }
}
