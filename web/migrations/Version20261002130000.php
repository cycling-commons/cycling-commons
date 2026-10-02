<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Indexes by rider for the season ballot's "one thing done" check.
 *
 * `App\Vote\VoterEligibility` asks, on every ballot page and every cast,
 * whether the rider has a route ride, a drawer confirmation or an applied
 * route correction. Each of the three tables was indexed by route or item
 * first, so each EXISTS read the whole table. These lead with `user_id`, and
 * carry the column the check also filters on.
 *
 * @see docs/specs/route-domain.md §2.2, §8d
 */
final class Version20261002130000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'route_ride, item_confirmation, route_suggestion: indexes by rider for voter eligibility';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_route_ride_user ON route_ride (user_id)');
        $this->addSql('CREATE INDEX idx_item_confirmation_user ON item_confirmation (user_id, source)');
        $this->addSql('CREATE INDEX idx_route_suggestion_user ON route_suggestion (user_id, status)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_route_suggestion_user');
        $this->addSql('DROP INDEX idx_item_confirmation_user');
        $this->addSql('DROP INDEX idx_route_ride_user');
    }
}
