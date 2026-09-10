<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The release-updates opt-in.
 *
 * One boolean, defaulting to **false**, which is the whole design: a rider who
 * never touches it is not on the list. Nobody is opted in by migration, by
 * signing up, or by having been here first. The consent itself is a row in
 * `consent_record` under the `release-updates` kind, so the flag says what is
 * true now and the record says when it was agreed to and to what wording. That
 * split already exists for photo licences and there was no reason to invent a
 * second shape for it.
 *
 * @see docs/specs/roadmap-and-changelog.md §4
 */
final class Version20260828010000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'users.updates_opt_in: consent to be told when a release ships. Off for everyone.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD updates_opt_in BOOLEAN DEFAULT FALSE NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP updates_opt_in');
    }
}
