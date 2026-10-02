<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The season ballot and its stored results.
 *
 * `season_vote` holds one row per vote for every votable category. A list is
 * (region, category, round); `slot` runs 1 to 3 and is unique per rider and
 * list, so the database itself refuses a fourth vote however many requests
 * arrive at once. Users and voted rows are plain ids with no foreign keys,
 * the house rule for community tables (route-domain.md §2.2).
 *
 * `season_result` holds a closed round's result, written once and never
 * updated, so a closed season cannot change when an account is deleted or a
 * place is retired. It has no entity: SeasonResults writes it with DBAL, and
 * the schema filter keeps it out of mapping diffs.
 *
 * @see docs/specs/route-domain.md §8c, §8d
 */
final class Version20261002100000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'season_vote and season_result: the season ballot and its stored results';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE season_vote (
                id BIGSERIAL NOT NULL,
                user_id BIGINT NOT NULL,
                region_id BIGINT NOT NULL,
                category VARCHAR(20) NOT NULL,
                subject_id BIGINT NOT NULL,
                bike_type VARCHAR(12) DEFAULT NULL,
                season VARCHAR(8) NOT NULL,
                round_start DATE NOT NULL,
                slot SMALLINT NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT season_vote_slot_range CHECK (slot BETWEEN 1 AND 3)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_season_vote_subject ON season_vote (user_id, region_id, category, round_start, subject_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_season_vote_slot ON season_vote (user_id, region_id, category, round_start, slot)');
        $this->addSql('CREATE INDEX idx_season_vote_list ON season_vote (region_id, category, round_start)');
        $this->addSql('CREATE INDEX idx_season_vote_subject ON season_vote (category, subject_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE season_result (
                id BIGSERIAL NOT NULL,
                region_id BIGINT NOT NULL,
                category VARCHAR(20) NOT NULL,
                bike_type VARCHAR(12) DEFAULT '' NOT NULL,
                season VARCHAR(8) NOT NULL,
                round_start DATE NOT NULL,
                subject_id BIGINT NOT NULL,
                subject_name VARCHAR(200) NOT NULL,
                votes INT NOT NULL,
                score INT NOT NULL,
                handicapped BOOLEAN NOT NULL,
                place SMALLINT DEFAULT NULL,
                wins_before SMALLINT NOT NULL,
                list_position SMALLINT NOT NULL,
                voters INT NOT NULL,
                frozen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_season_result ON season_result (region_id, category, bike_type, round_start, subject_id)');
        $this->addSql('CREATE INDEX idx_season_result_history ON season_result (region_id, category, bike_type, subject_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE season_result');
        $this->addSql('DROP TABLE season_vote');
    }
}
