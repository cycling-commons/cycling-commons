<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfill the submitter's own potability answer for water points approved
 * BEFORE ModerationService started carrying it across.
 *
 * Without this, the people who have already added a fountain — the only ones
 * who have used the feature at all — keep being asked the very question they
 * answered on the form, and the fix looks broken to exactly the riders it was
 * written for.
 *
 * `source = 'form'`, so these rows are remembered but never counted: not in
 * the tally, not in the verified derivation (moderation-and-contribution.md
 * §6.3). The `NOT EXISTS` guard leaves any real drawer confirmation alone —
 * a rider who has since confirmed the place keeps the counted row.
 *
 * "Unsigned — use judgement" is not a claim either way and is skipped, exactly
 * as the live path skips it.
 */
final class Version20260802110000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Backfill form-sourced potability answers for already-approved water submissions';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO item_confirmation (item_id, user_id, stance, source, created_at, updated_at)
            SELECT s.item_id,
                   s.user_id,
                   CASE WHEN s.changes->'potable'->>'now' LIKE 'Yes%' THEN 'potable' ELSE 'not_potable' END,
                   'form',
                   now(),
                   now()
              FROM submission s
              JOIN item i ON i.id = s.item_id
             WHERE s.status = 'approved'
               AND s.letter = 'C'
               AND i.letter = 'C'
               AND (s.changes->'potable'->>'now' LIKE 'Yes%' OR s.changes->'potable'->>'now' LIKE 'No%')
               AND EXISTS (SELECT 1 FROM users u WHERE u.id = s.user_id)
               AND NOT EXISTS (
                     SELECT 1 FROM item_confirmation c
                      WHERE c.item_id = s.item_id AND c.user_id = s.user_id
                   )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // Only the rows this migration could have created: form-sourced ones.
        // A drawer confirmation is a rider's own act and is never touched.
        $this->addSql("DELETE FROM item_confirmation WHERE source = 'form'");
    }
}
