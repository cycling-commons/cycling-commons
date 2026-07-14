<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260714210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'moderator_area — region/country moderation-scope assignments (moderator-areas spec 2026-07-14)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE moderator_area (
                id BIGSERIAL NOT NULL,
                user_id BIGINT NOT NULL,
                region_id BIGINT DEFAULT NULL,
                country_code VARCHAR(2) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT fk_moderator_area_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_moderator_area_region FOREIGN KEY (region_id) REFERENCES region (id) ON DELETE CASCADE,
                CONSTRAINT chk_moderator_area_kind CHECK ((region_id IS NULL) <> (country_code IS NULL))
            )
            SQL);
        // PG18 NULLS NOT DISTINCT: duplicate (user, NULL, 'BE') rows are real duplicates.
        $this->addSql('CREATE UNIQUE INDEX uniq_moderator_area ON moderator_area (user_id, region_id, country_code) NULLS NOT DISTINCT');
        $this->addSql('CREATE INDEX idx_moderator_area_user ON moderator_area (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE moderator_area');
    }
}
