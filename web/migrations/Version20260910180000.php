<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Potability unknown" says so, and two mineral springs stop claiming Yes.
 *
 * The water form's middle potability answer read "Unsigned — use judgement",
 * which describes a missing sign rather than the state of our knowledge. A
 * rider reading the drawer could not tell it apart from a claim, and the map
 * key described only the blank case. It is "Unknown" now, the same word the
 * three selects beside it use (owner 2026-09-10). Not "Not known": two
 * readers prefix-match "No" as non-potable (ModerationService and
 * icons.js), and that spelling would have painted an unknown tap as a bad one.
 *
 * The two Spa pouhons are the reason this came up. Both were hand-seeded with
 * `potable = 'Yes (public supply)'` while their own note in the same block
 * said "not SWDE tap water". Nothing measured them, no register carries
 * them, no rider confirmed them. They become Unknown, which draws the
 * unfilled drop. The Dutch register's 3283 rows keep their Yes: an authority
 * published that.
 *
 * @see docs/specs/edit-items/B-water-food.md
 */
final class Version20260910180000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'potable: "Unsigned — use judgement" becomes "Unknown", and two unbacked Yes claims are cleared';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE item
               SET attributes = jsonb_set(attributes, '{potable}', '"Unknown"')
             WHERE letter = 'B' AND attributes->>'potable' = 'Unsigned — use judgement'
            SQL);
        // Named by their seed refs, so a re-run of app:catalog:seed-manual and
        // this migration agree, and no register or OSM row is touched.
        $this->addSql(<<<'SQL'
            UPDATE item
               SET attributes = jsonb_set(attributes, '{potable}', '"Unknown"')
             WHERE source_ref IN ('manual:pouhon-la-sauveniere-spa', 'manual:source-barisart-spa')
               AND attributes->>'potable' = 'Yes (public supply)'
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // Only the rename is reversible. The two springs never had evidence
        // for their Yes, so putting it back would re-publish a claim nobody
        // can support.
        $this->addSql(<<<'SQL'
            UPDATE item
               SET attributes = jsonb_set(attributes, '{potable}', '"Unsigned — use judgement"')
             WHERE letter = 'B' AND attributes->>'potable' = 'Unknown'
            SQL);
    }
}
