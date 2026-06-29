<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add deletion_code and deletion_requested_at columns to the users table (GDPR Art. 17 / Task 9).
 */
final class Version20260629001745 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add deletion_code and deletion_requested_at to users (GDPR Art. 17 account deletion)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE users ADD deletion_code VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD deletion_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE users DROP deletion_code');
        $this->addSql('ALTER TABLE users DROP deletion_requested_at');
    }
}
