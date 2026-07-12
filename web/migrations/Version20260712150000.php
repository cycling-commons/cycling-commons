<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user_message — moderation-feedback inbox (messages spec M1/M10; first real user_id FK, ON DELETE CASCADE)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE user_message (
                id BIGSERIAL NOT NULL,
                user_id BIGINT NOT NULL,
                kind VARCHAR(32) NOT NULL,
                sender VARCHAR(8) NOT NULL,
                sender_id BIGINT DEFAULT NULL,
                channel VARCHAR(12) NOT NULL,
                ref_id BIGINT NOT NULL,
                ref_label VARCHAR(220) NOT NULL,
                body_key VARCHAR(120) DEFAULT NULL,
                body_params JSONB DEFAULT NULL,
                body_text TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id),
                CONSTRAINT fk_user_message_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_user_message_unread ON user_message (user_id, read_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_message');
    }
}
