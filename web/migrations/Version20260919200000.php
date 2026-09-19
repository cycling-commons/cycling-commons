<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A bug report records when it was fixed (owner 2026-09-19): the known-issues
 * Fixed tab showed the last-change date, which any edit moves. Bugs already
 * fixed get their release's date when the release has one, else their last
 * change, the best date there is for them.
 *
 * @see docs/specs/contact-and-support.md §10
 */
final class Version20260919200000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'bug_report.resolved_at';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bug_report ADD resolved_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql("UPDATE bug_report b
                          SET resolved_at = COALESCE(
                                (SELECT rt.released_at::timestamptz FROM release_tag rt WHERE rt.tag = b.fix_release),
                                b.updated_at)
                        WHERE b.status = 'resolved'");
        $this->addSql("COMMENT ON COLUMN bug_report.resolved_at IS 'When the status last became resolved; null otherwise.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bug_report DROP resolved_at');
    }
}
