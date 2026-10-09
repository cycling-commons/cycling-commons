<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A time-limited suspension of an account by an administrator
 * (docs/specs/account-and-auth.md §6.8): until when, since when, on which
 * ground, the facts the holder was sent, and the administrator who decided.
 * `suspended_by` is a user id, so it carries a foreign key that clears it when
 * that administrator's account is deleted (§6.3).
 */
final class Version20261009050000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'users: suspended_until, suspended_at, suspension_ground, suspension_facts, suspended_by';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD suspended_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD suspended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD suspension_ground VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD suspension_facts TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD suspended_by INT DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT fk_users_suspended_by FOREIGN KEY (suspended_by) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_users_suspended_by ON users (suspended_by)');
        $this->addSql("COMMENT ON COLUMN users.suspended_until IS 'Suspended by an administrator until this moment; signing in is refused before it. NULL: never suspended.'");
        $this->addSql("COMMENT ON COLUMN users.suspension_facts IS 'The facts of the last suspension, as sent to the holder in the statement of reasons.'");
        $this->addSql("COMMENT ON COLUMN users.suspended_by IS 'The administrator who decided the last suspension; NULL once that account is deleted.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP CONSTRAINT fk_users_suspended_by');
        $this->addSql('DROP INDEX idx_users_suspended_by');
        $this->addSql('ALTER TABLE users DROP suspended_by');
        $this->addSql('ALTER TABLE users DROP suspension_facts');
        $this->addSql('ALTER TABLE users DROP suspension_ground');
        $this->addSql('ALTER TABLE users DROP suspended_at');
        $this->addSql('ALTER TABLE users DROP suspended_until');
    }
}
