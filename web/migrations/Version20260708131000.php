<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260708131000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'route_suggestion — moderated correction channel (route-domain v1 phase 2, spec §4.2)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE route_suggestion (
                id BIGSERIAL NOT NULL,
                route_id BIGINT NOT NULL,
                user_id BIGINT NOT NULL,
                reason VARCHAR(20) NOT NULL,
                note TEXT DEFAULT NULL,
                status VARCHAR(12) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                resolved_by BIGINT DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_route_suggestion_route ON route_suggestion (route_id, status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE route_suggestion');
    }
}
