<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Two things a bug desk needs that the outcome note cannot carry.
 *
 * **`internal_note`.** The outcome note is written FOR the reporter: it is the
 * text mailed to them and, once published, the text on `/known-issues`. So it
 * cannot hold "same root cause as #7", "waiting on the Valhalla rebuild", or
 * anybody's name. Without a second field a curator either says nothing or says
 * it in the wrong place, and there is no third option.
 *
 * **`fix_release`.** The git tag the fix lands in. Short and nullable: most
 * reports never get one, and a report that does has exactly one.
 *
 * @see docs/specs/contact-and-support.md §9
 */
final class Version20260828200000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'bug_report: a curator-only note, and the release a fix lands in.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bug_report ADD internal_note TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE bug_report ADD fix_release VARCHAR(64) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bug_report DROP fix_release');
        $this->addSql('ALTER TABLE bug_report DROP internal_note');
    }
}
