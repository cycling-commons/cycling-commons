<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The GitHub issue an admin opened for a public bug, by number
 * (docs/specs/contact-and-support.md §9, owner 2026-09-08). A link, not a sync.
 */
final class Version20260908163000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'bug_report.github_issue: the issue an admin opened on the public repository';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bug_report ADD github_issue INT DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bug_report DROP github_issue');
    }
}
