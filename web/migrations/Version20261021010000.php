<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A fourth roadmap group, `progress` ("In progress"): work that is partly on
 * the site and still growing, such as new countries (roadmap-and-changelog.md
 * §6a). The status check takes it beside now, next and later.
 */
final class Version20261021010000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'roadmap_item.status: a fourth group, progress (In progress)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE roadmap_item DROP CONSTRAINT roadmap_item_status_check');
        $this->addSql("ALTER TABLE roadmap_item ADD CONSTRAINT roadmap_item_status_check CHECK (status IN ('progress', 'now', 'next', 'later'))");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // An item in progress goes back to Now, the group it was split from.
        $this->addSql("UPDATE roadmap_item SET status = 'now' WHERE status = 'progress'");
        $this->addSql('ALTER TABLE roadmap_item DROP CONSTRAINT roadmap_item_status_check');
        $this->addSql("ALTER TABLE roadmap_item ADD CONSTRAINT roadmap_item_status_check CHECK (status IN ('now', 'next', 'later'))");
    }
}
