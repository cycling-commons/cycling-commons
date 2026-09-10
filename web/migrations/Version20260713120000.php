<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260713120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'item_confirmation — community potability/existence confirmations for non-votable utilities';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE item_confirmation (
                id BIGSERIAL NOT NULL,
                item_id BIGINT NOT NULL,
                user_id BIGINT NOT NULL,
                stance VARCHAR(12) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_item_confirmation ON item_confirmation (item_id, user_id)');
        $this->addSql('CREATE INDEX idx_item_confirmation_tally ON item_confirmation (item_id, stance)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE item_confirmation');
    }
}
