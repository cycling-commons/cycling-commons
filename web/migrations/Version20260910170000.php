<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A confirmation records whether a curator made it.
 *
 * A curator's word settles the verified state on its own
 * (moderation-and-contribution.md §10.1), but nothing wrote that down. Both
 * readers inferred it from arithmetic instead: the evidence ladder called a
 * verified row with fewer confirmations than the threshold "curator", and the
 * drawer counted heads and said "1 rider confirmed" over a curator's answer
 * (owner 2026-09-10: "curator confirmed, as this is stronger"). Arithmetic is
 * the wrong instrument: move map.item_verify_threshold, or let one more rider
 * confirm, and the same row names a different witness.
 *
 * Backfill reads the roles each confirmer holds today, which is the only
 * evidence the old rows left. From here the column is written at the moment
 * of the answer, and a promotion or demotion afterwards cannot rewrite who
 * was standing there.
 *
 * @see docs/specs/moderation-and-contribution.md §10.1
 * @see docs/specs/data-provider-hierarchy.md §6.7.7
 */
final class Version20260910170000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'item_confirmation.by_curator: the curator receipt, recorded instead of inferred';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item_confirmation ADD COLUMN IF NOT EXISTS by_curator BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql(<<<'SQL'
            UPDATE item_confirmation c SET by_curator = true
              FROM users u
             WHERE u.id = c.user_id
               AND (jsonb_exists(u.roles::jsonb, 'ROLE_CURATOR') OR jsonb_exists(u.roles::jsonb, 'ROLE_ADMIN'))
            SQL);
        // The drawer and the ladder both ask "is any vouching row a curator's".
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_item_confirmation_curator ON item_confirmation (item_id) WHERE by_curator');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_item_confirmation_curator');
        $this->addSql('ALTER TABLE item_confirmation DROP COLUMN IF EXISTS by_curator');
    }
}
