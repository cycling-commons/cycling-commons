<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260710120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'route_ride — "I rode this" confirmations (route-domain v1 phase 3, spec §4.2)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE route_ride (
                id BIGSERIAL NOT NULL,
                route_id BIGINT NOT NULL,
                user_id BIGINT NOT NULL,
                bike_type VARCHAR(12) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_route_ride ON route_ride (route_id, user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE route_ride');
    }
}
