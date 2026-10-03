<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Room for a longer season ballot.
 *
 * `season_vote.slot` is the rider's rank on the ballot, and how many votes a
 * rider has per list is `App\Vote\BallotRules::VOTES_PER_LIST`. The CHECK held
 * that number too (1 to 3), so going to 5 votes needed a migration. It now
 * only guards 1 to `BallotRules::MAX_SLOTS` (10), and the code holds the rule.
 *
 * @see docs/specs/route-domain.md §8c, §8d
 */
final class Version20261002140000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'season_vote: slot CHECK guards 1 to 10, the ballot length lives in BallotRules';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE season_vote DROP CONSTRAINT season_vote_slot_range');
        $this->addSql('ALTER TABLE season_vote ADD CONSTRAINT season_vote_slot_range CHECK (slot BETWEEN 1 AND 10)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE season_vote DROP CONSTRAINT season_vote_slot_range');
        $this->addSql('ALTER TABLE season_vote ADD CONSTRAINT season_vote_slot_range CHECK (slot BETWEEN 1 AND 3)');
    }
}
