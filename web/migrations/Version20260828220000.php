<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `content_report.from_path`: the page the reporter was looking at.
 *
 * Every report link now reads "Report this page" and carries the path it was
 * clicked from, so one wording works on a region page, a profile and the map
 * alike. The reporter never types it and never sees it; a curator opening the
 * report gets taken straight to what the person was looking at.
 *
 * Path only, and only our own: the same rule the bug form applies, because a
 * query string on this site can carry somebody's search terms.
 *
 * @see docs/specs/content-reports.md §5
 */
final class Version20260828220000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'content_report.from_path: the page the report was made from.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_report ADD from_path VARCHAR(500) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_report DROP from_path');
    }
}
