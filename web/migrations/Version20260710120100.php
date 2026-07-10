<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260710120100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'route_vote — typed seasonal votes (route-domain v1 phase 3, spec §4.2)';
    }

    public function up(Schema $schema): void
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

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE route_vote');
    }
}
