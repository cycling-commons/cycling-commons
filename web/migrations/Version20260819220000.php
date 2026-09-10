<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260819220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "map chrome theme: users.map_theme ('dark' | 'light') rider preference — map-and-search.md §4.6";
    }

    public function up(Schema $schema): void
    {
        // 'dark' = the look the map has always had, so every existing rider
        // keeps it until they choose otherwise. Stored as the MapTheme value string.
        $this->addSql("ALTER TABLE users ADD map_theme VARCHAR(16) DEFAULT 'dark' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP map_theme');
    }
}
