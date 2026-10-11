<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * When a rider who asked for a country was told it went live
 * (docs/specs/moderation-and-contribution.md §11.1, owner 2026-10-11). Set
 * once by the admin desk's notify action, so pressing it again tells nobody
 * twice.
 */
final class Version20261011150000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'country_interest.notified_at: when the rider was told the country is live';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE country_interest ADD notified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE country_interest DROP notified_at');
    }
}
