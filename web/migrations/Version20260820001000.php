<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260820001000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'media_upload.storage_bucket: the row records the FULL bucket name (media-storage-architecture.md S2.1)';
    }

    public function up(Schema $schema): void
    {
        // Empty default: rows minted before the column existed have no bucket
        // recorded, and storage refuses to address them rather than guessing.
        $this->addSql("ALTER TABLE media_upload ADD storage_bucket VARCHAR(63) DEFAULT '' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_upload DROP storage_bucket');
    }
}
