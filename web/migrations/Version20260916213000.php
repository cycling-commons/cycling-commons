<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A rider's edit of their own route rides the existing correction channel: the
 * route-scoped counterparts of `submission.changes` and
 * `change_history.submission_id`.
 *
 * @see docs/specs/route-domain.md §7.1
 * @see docs/specs/edit-items/R-quality-rides.md
 */
final class Version20260916213000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'route_suggestion.changes, route_change_history.suggestion_id (rider metadata edits on their own route)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE route_suggestion ADD changes JSONB DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN route_suggestion.changes IS 'Proposed metadata, {field: {\"was\": …, \"now\": …}}, the route counterpart of submission.changes. Set only for reason=metadata; NULL on every other reason.'");
        $this->addSql('ALTER TABLE route_change_history ADD suggestion_id BIGINT DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN route_change_history.suggestion_id IS 'The correction whose approval wrote this row, the route counterpart of change_history.submission_id. NULL when a curator edited the route directly on the desk. No foreign key (route-domain.md §2.2).'");
        $this->addSql('CREATE INDEX idx_route_history_suggestion ON route_change_history (suggestion_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_route_history_suggestion');
        $this->addSql('ALTER TABLE route_change_history DROP suggestion_id');
        $this->addSql('ALTER TABLE route_suggestion DROP changes');
    }
}
