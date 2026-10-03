<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A season ballot is a draft until the rider submits it.
 *
 * `season_vote.submitted_at` is stamped on all of a rider's votes in one list
 * at once when they submit. Only submitted votes count; a submitted ballot is
 * final; a draft left when voting closes is deleted by `app:vote:freeze`.
 *
 * @see docs/specs/route-domain.md §8c, §8d
 */
final class Version20261002150000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'season_vote: submitted_at, a ballot counts only once the rider submits it';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE season_vote ADD submitted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE season_vote DROP submitted_at');
    }
}
