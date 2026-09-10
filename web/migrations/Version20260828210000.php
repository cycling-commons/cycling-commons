<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `bug_report.public_body`: what the known-issues list says about a bug.
 *
 * The list was printing the outcome note, which is a REPLY. "Fixed in today''s
 * release, thank you for spotting it" reads as an answer to one person, because
 * it is one, and it is the wrong text on a public page that a stranger reads
 * cold. The public title already had its own field; the description needed one
 * for the same reason.
 *
 * @see docs/specs/contact-and-support.md §10
 */
final class Version20260828210000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'bug_report.public_body: the public description, separate from the reply.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bug_report ADD public_body TEXT DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bug_report DROP public_body');
    }
}
