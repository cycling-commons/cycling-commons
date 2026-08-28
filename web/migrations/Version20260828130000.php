<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The two columns the dormancy sweep needs.
 *
 * `last_login_at` is the clock. It is **backfilled to `created_at`** rather
 * than left null, because null would mean "never signed in", and on the day
 * this ships that is false for everybody: they simply signed in before we
 * started writing it down. Treating an existing account as last seen when it
 * was created is the conservative reading, and the worst it does is start
 * somebody's twelve months earlier than the truth, which only ever sends a
 * reminder sooner.
 *
 * The three `inactivity_*_at` columns record when each warning went out, one
 * per rung. Three columns rather than one "current stage", because deletion
 * asks "were they told three times?" and that question needs a per-rung answer.
 * Signing in clears all three at once.
 *
 * @see docs/specs/account-and-auth.md §6.5
 */
final class Version20260828130000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'users.last_login_at + three inactivity notice stamps: the clock and the ladder for closing unused accounts.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD inactivity_12m_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD inactivity_22m_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD inactivity_23m_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        // Nobody's clock starts at null on the day we start keeping it.
        $this->addSql('UPDATE users SET last_login_at = created_at WHERE last_login_at IS NULL');
        // The sweep reads this column across the whole table on a schedule.
        $this->addSql('CREATE INDEX idx_users_last_login_at ON users (last_login_at)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_users_last_login_at');
        $this->addSql('ALTER TABLE users DROP inactivity_23m_at');
        $this->addSql('ALTER TABLE users DROP inactivity_22m_at');
        $this->addSql('ALTER TABLE users DROP inactivity_12m_at');
        $this->addSql('ALTER TABLE users DROP last_login_at');
    }
}
