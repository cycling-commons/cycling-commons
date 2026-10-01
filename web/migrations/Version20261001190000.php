<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * How often a rider on the release list agreed to hear from us (owner
 * 2026-10-01): 'big' is big news only, at most 4 times a year; 'every' is every
 * update, at most 2 times a month (App\Account\UpdatesCadence,
 * docs/specs/roadmap-and-changelog.md §4).
 *
 * Every existing row gets 'big', which is what a rider opted in under the v1
 * wording ("a few times a year") agreed to. The column records a preference
 * only; `updates_opt_in` alone decides whether anything is sent.
 */
final class Version20261001190000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'users.updates_cadence: big (at most 4 a year) or every (at most 2 a month), default big';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD updates_cadence VARCHAR(8) DEFAULT 'big' NOT NULL");
        $this->addSql("COMMENT ON COLUMN users.updates_cadence IS 'Release list cadence the rider chose: big (big news only, at most 4 times a year) or every (every update, at most 2 times a month). Sends nothing by itself; updates_opt_in decides that.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP updates_cadence');
    }
}
