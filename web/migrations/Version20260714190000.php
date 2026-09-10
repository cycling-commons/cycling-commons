<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260714190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'users: unique index on uuid (public rider profile lookups; v7 values, no dedup needed)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_users_uuid ON users (uuid)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_users_uuid');
    }
}
