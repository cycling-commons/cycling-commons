<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Every vote lives in `season_vote`; `route_vote` goes.
 *
 * `route_vote` holds no real votes: the owner confirmed on 2026-10-02 that
 * nobody has voted yet, on any deployment. So nothing is copied. The
 * migration still refuses to run if the table is not empty, so a vote cast
 * after that check is never dropped silently: the deploy stops, and the
 * rows are looked at by hand.
 *
 * @see docs/specs/route-domain.md §8d
 */
final class Version20261002110000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Drop route_vote, which holds no votes (refuses if it does)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $rows = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM route_vote');
        $this->abortIf($rows > 0, sprintf('route_vote holds %d row(s); no real votes were expected (owner 2026-10-02). Look at them before dropping the table.', $rows));
        $this->addSql('DROP TABLE route_vote');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE route_vote (
                id BIGSERIAL NOT NULL,
                route_id BIGINT NOT NULL,
                user_id BIGINT NOT NULL,
                season VARCHAR(8) NOT NULL,
                bike_type VARCHAR(12) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_route_vote ON route_vote (route_id, user_id, season)');
        $this->addSql('CREATE INDEX idx_route_vote_rank ON route_vote (route_id, season, bike_type)');
    }
}
