<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260820002000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'media_upload: the shard column dies - the full bucket name is the one key, its last five characters the URL segment';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_upload DROP storage_shard');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE media_upload ADD storage_shard VARCHAR(16) DEFAULT '' NOT NULL");
    }
}
