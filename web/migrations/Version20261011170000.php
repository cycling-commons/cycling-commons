<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * When the support address was told a report arrived
 * (docs/specs/contact-and-support.md §7, owner 2026-10-11). The report alert
 * mails every report without it and stamps them in the same statement.
 * Reports already on file count as told: the desk has shown them all along,
 * and the first alert should not list the whole history.
 */
final class Version20261011170000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'content_report.team_told_at: when the support address was told the report arrived';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_report ADD team_told_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE content_report SET team_told_at = created_at');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_report DROP team_told_at');
    }
}
