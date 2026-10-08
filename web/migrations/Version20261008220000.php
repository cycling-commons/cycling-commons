<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The map hints a person closed (App\Map\MapHint), stored on the account so a
 * hint read once stays closed on every device (docs/specs/map-and-search.md §4.5).
 */
final class Version20261008220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'users.closed_hints: map hints a person closed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD closed_hints JSON DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP closed_hints');
    }
}
