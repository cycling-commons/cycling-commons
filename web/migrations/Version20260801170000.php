<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Whether a rider reads 14:30 or 2:30 PM (docs/specs/account-and-auth.md §9).
 *
 * A column of its own rather than something derived from date_format: wanting
 * 01-08-2026 says nothing about wanting a 24-hour clock, and the two really are
 * separate habits.
 */
final class Version20260801170000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'users.time_format: 24-hour or 12-hour clock';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD time_format VARCHAR(10) DEFAULT 'auto' NOT NULL");
        $this->addSql("COMMENT ON COLUMN users.time_format IS 'auto|h24|h12 — App\\Account\\TimeFormat. auto follows the interface language.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP time_format');
    }
}
