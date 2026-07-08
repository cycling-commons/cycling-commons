<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260708130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'route_change_history — append-only curator route audit (route-domain v1 phase 2, spec D9)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE route_change_history (
                id BIGSERIAL NOT NULL,
                route_id BIGINT NOT NULL,
                field VARCHAR(80) NOT NULL,
                old_value JSONB DEFAULT NULL,
                new_value JSONB DEFAULT NULL,
                changed_by BIGINT NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_route_history_time ON route_change_history (route_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE route_change_history');
    }
}
