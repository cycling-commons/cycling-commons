<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One definition of verified, applied to the rows already holding the proof.
 *
 * Until now a single confirmation drew a full pin while the record stayed
 * Unverified, so riders had been vouching for places that the database never
 * promoted. Dropping the display short-circuit without this sweep would take
 * the full pin back off every one of them and throw away real signal.
 *
 * The threshold is `map.item_verify_threshold`, 2 at the time of writing. It is
 * hard-coded here on purpose: a migration records what happened on the day it
 * ran, and re-running it years later against a changed dial would rewrite
 * history rather than repeat it.
 *
 * @see docs/specs/moderation-and-contribution.md §10
 */
final class Version20260909210000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'item: verify the rows two riders had already vouched for';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE item i
               SET state = 'verified'
             WHERE i.state = 'unverified'
               AND (SELECT COUNT(*) FROM item_confirmation c
                     WHERE c.item_id = i.id
                       AND c.source <> 'form'
                       AND c.stance IN ('potable', 'exists')) >= 2
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // Irreversible by design: the promoted rows are indistinguishable from
        // rows a curator verified by hand, and demoting the latter would be a
        // worse loss than leaving the former.
        $this->throwIrreversibleMigrationException();
    }
}
